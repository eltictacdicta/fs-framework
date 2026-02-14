<?php
/**
 * Librería segura para subida de archivos por chunks
 * 
 * Esta librería proporciona funcionalidad de subida de archivos grandes
 * por fragmentos con validaciones de seguridad a nivel de archivo.
 * 
 * IMPORTANTE: La autenticación y autorización deben ser manejadas por
 * el controlador que instancie esta clase (ej: dentro de private_core()).
 * Esta clase se encarga de la seguridad a nivel de archivo:
 * - Validación de extensiones de archivo
 * - Validación de tamaño máximo
 * - Validación de firma mágica de archivos
 * - Sanitización de nombres de archivo
 * - Prevención de Path Traversal
 * - Directorio de trabajo aislado
 * - Limpieza automática de chunks huérfanos
 * 
 * Uso desde un controlador (dentro de private_core):
 * ```php
 * $uploader = new fs_secure_chunked_upload('/ruta/destino/', ['zip', 'gz']);
 * $result = $uploader->handle_chunk();
 * if ($result['complete']) {
 *     // Archivo completo en $result['file_path']
 * }
 * ```
 * 
 * @author FSFramework Team
 * @license LGPL-3.0-or-later
 * @version 2.0.0
 */
class fs_secure_chunked_upload
{
    private const SAFE_TOKEN_REGEX = '/[^a-zA-Z0-9_-]/';

    /**
     * Directorio destino para los archivos
     * @var string
     */
    protected $upload_dir;

    /**
     * Directorio temporal para chunks
     * @var string
     */
    protected $temp_dir;

    /**
     * Extensiones de archivo permitidas
     * @var array
     */
    protected $allowed_extensions;

    /**
     * Tamaño máximo de archivo en bytes
     * @var int
     */
    protected $max_file_size;

    /**
     * Último mensaje de error
     * @var string
     */
    protected $last_error;

    /**
     * Nick del usuario autenticado (informativo, para logs)
     * @var string
     */
    protected $user_nick;

    /**
     * Callback a ejecutar cuando se complete la subida
     * @var callable|null
     */
    protected $on_complete_callback;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    private $request;

    /**
     * Identificador del contexto (para aislar directorios temporales)
     * @var string
     */
    private $context_id;

    /**
     * Constructor
     * 
     * NOTA: Esta clase NO realiza autenticación. Debe ser instanciada
     * únicamente desde código ya autenticado (ej: private_core() de un controlador).
     *
     * @param string $upload_dir Directorio destino para archivos
     * @param array $allowed_extensions Extensiones permitidas (sin punto)
     * @param int $max_file_size_mb Tamaño máximo en MB (default: 500)
     * @param string $context_id Identificador para aislar temp dir (default: 'default')
     * @param string $user_nick Nick del usuario (para logging, default: 'system')
     * @throws Exception Si no se pueden crear los directorios necesarios
     */
    public function __construct(
        $upload_dir,
        $allowed_extensions = [],
        $max_file_size_mb = 500,
        $context_id = 'default',
        $user_nick = 'system'
    ) {
        // Obtener request de Symfony
        $this->request = \FSFramework\Core\Kernel::request();

        // Guardar configuración
        $this->context_id = preg_replace(self::SAFE_TOKEN_REGEX, '', $context_id);
        $this->user_nick = preg_replace(self::SAFE_TOKEN_REGEX, '', $user_nick);
        $this->upload_dir = $this->sanitize_path($upload_dir);
        $this->allowed_extensions = array_map('strtolower', $allowed_extensions);
        $this->max_file_size = $max_file_size_mb * 1024 * 1024;
        $this->last_error = '';
        $this->on_complete_callback = null;

        // Directorio temporal aislado por contexto
        $this->temp_dir = $this->get_temp_dir();

        // Crear directorios si no existen
        $this->ensure_directory($this->upload_dir);
        $this->ensure_directory($this->temp_dir);

        // Limpiar chunks huérfanos automáticamente (1% de probabilidad)
        if (mt_rand(1, 100) === 1) {
            $this->cleanup_orphan_chunks(24);
        }
    }

    /**
     * Manejar un chunk subido
     *
     * @param string|null $custom_filename Nombre personalizado para el archivo final
     * @return array Resultado con 'success', 'complete', 'file_path', 'message'
     */
    public function handle_chunk($custom_filename = null)
    {
        $chunkParams = $this->getChunkParams($custom_filename);

        // Manejar petición GET (verificar si chunk existe)
        if ($this->request->isMethod('GET')) {
            return $this->check_chunk_exists($chunkParams['identifier'], $chunkParams['chunk_number']);
        }

        // Manejar petición POST (subir chunk)
        if ($this->request->isMethod('POST')) {
            return $this->receive_chunk(
                $chunkParams['identifier'],
                $chunkParams['filename'],
                $chunkParams['chunk_number'],
                $chunkParams['total_chunks'],
                $chunkParams['total_size'],
                $chunkParams['custom_filename']
            );
        }

        return $this->error_response('Método no soportado');
    }

    /**
     * Verificar si un chunk ya existe (para reanudar subidas)
     */
    protected function check_chunk_exists($identifier, $chunk_number)
    {
        $chunk_file = $this->get_chunk_path($identifier, $chunk_number);

        if (file_exists($chunk_file)) {
            return [
                'success' => true,
                'complete' => false,
                'message' => 'Chunk exists'
            ];
        }

        http_response_code(404);
        return [
            'success' => false,
            'complete' => false,
            'message' => 'Chunk not found'
        ];
    }

    /**
     * Recibir y guardar un chunk
     */
    protected function receive_chunk($identifier, $filename, $chunk_number, $total_chunks, $total_size, $custom_filename)
    {
        $validationError = $this->validateChunkRequest($filename, $total_size);
        if ($validationError !== null) {
            return $this->error_response($validationError);
        }

        $file = $this->request->files->get('file');

        // Crear directorio para chunks de este archivo
        $chunk_dir = $this->temp_dir . $this->sanitize_identifier($identifier) . '/';
        $this->ensure_directory($chunk_dir);

        // Guardar chunk
        $chunk_file = $this->get_chunk_path($identifier, $chunk_number);

        try {
            $file->move(dirname($chunk_file), basename($chunk_file));
        } catch (\Exception $e) {
            return $this->error_response('Error al guardar el chunk: ' . $e->getMessage());
        }

        if ($this->all_chunks_received($identifier, $total_chunks)) {
            return $this->finalizeUpload($identifier, $total_chunks, $filename, $custom_filename);
        }

        // Chunk recibido pero archivo incompleto
        return [
            'success' => true,
            'complete' => false,
            'chunk' => $chunk_number,
            'total' => $total_chunks,
            'message' => "Chunk {$chunk_number}/{$total_chunks} recibido"
        ];
    }

    /**
     * Validar archivo final (firma mágica)
     * 
     * @param string $file_path
     * @return bool
     */
    private function validate_final_file($file_path)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // Firmas mágicas por tipo de archivo
        $signatures = [
            'zip' => ['504B0304', '504B0506', '504B0708'],
            'gz' => ['1F8B08'],
            'pdf' => ['255044462D'],
            'png' => ['89504E470D0A1A0A'],
            'jpg' => ['FFD8FFE0', 'FFD8FFE1', 'FFD8FFEE', 'FFD8FFDB'],
            'gif' => ['474946383961', '474946383761'],
        ];

        if (isset($signatures[$ext])) {
            $handle = fopen($file_path, 'rb');
            if ($handle) {
                $bytes = fread($handle, 8);
                fclose($handle);
                $hex = strtoupper(bin2hex($bytes));

                $valid = false;
                foreach ($signatures[$ext] as $sig) {
                    if (strpos($hex, $sig) === 0) {
                        $valid = true;
                        break;
                    }
                }

                if (!$valid) {
                    $this->log_event('INVALID_FILE_SIGNATURE', [
                        'file' => basename($file_path),
                        'expected_ext' => $ext,
                        'actual_signature' => substr($hex, 0, 16)
                    ]);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Verificar si todos los chunks fueron recibidos
     */
    private function all_chunks_received($identifier, $total_chunks)
    {
        for ($i = 1; $i <= $total_chunks; $i++) {
            if (!file_exists($this->get_chunk_path($identifier, $i))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Combinar todos los chunks en un archivo final
     */
    private function combine_chunks($identifier, $total_chunks, $final_path)
    {
        $fp = fopen($final_path, 'wb');
        if (!$fp) {
            $this->last_error = 'No se pudo crear el archivo destino';
            return false;
        }

        for ($i = 1; $i <= $total_chunks; $i++) {
            $chunk_file = $this->get_chunk_path($identifier, $i);
            $chunk_data = file_get_contents($chunk_file);
            if ($chunk_data === false) {
                fclose($fp);
                @unlink($final_path);
                $this->last_error = "No se pudo leer el chunk {$i}";
                return false;
            }
            fwrite($fp, $chunk_data);
        }

        fclose($fp);
        return true;
    }

    /**
     * Limpiar chunks temporales de un archivo
     */
    private function cleanup_chunks($identifier)
    {
        $chunk_dir = $this->temp_dir . $this->sanitize_identifier($identifier) . '/';

        if (is_dir($chunk_dir)) {
            $files = glob($chunk_dir . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($chunk_dir);
        }
    }

    /**
     * Limpiar chunks huérfanos (más antiguos que X horas)
     * 
     * @param int $hours Horas de antigüedad
     * @return int Número de chunks eliminados
     */
    public function cleanup_orphan_chunks($hours = 24)
    {
        $count = 0;
        $threshold = time() - ($hours * 3600);

        if (is_dir($this->temp_dir)) {
            $directories = glob($this->temp_dir . '*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                if ($this->cleanup_orphan_directory($dir, $threshold, $count)) {
                    @rmdir($dir);
                }
            }
        }

        return $count;
    }

    /**
     * Obtener ruta de un chunk
     */
    private function get_chunk_path($identifier, $chunk_number)
    {
        $safe_id = $this->sanitize_identifier($identifier);
        return $this->temp_dir . $safe_id . '/chunk_' . str_pad($chunk_number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Obtener directorio temporal por contexto
     */
    private function get_temp_dir()
    {
        $base = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__);
        return $base . '/tmp/chunks/' . $this->context_id . '/';
    }

    /**
     * Sanitizar identificador
     */
    private function sanitize_identifier($identifier)
    {
        return preg_replace(self::SAFE_TOKEN_REGEX, '', $identifier);
    }

    /**
     * Sanitizar nombre de archivo
     */
    protected function sanitize_filename($filename)
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Prevenir doble extensión (.php.zip, etc.)
        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            $ext = array_pop($parts);
            $filename = implode('_', $parts) . '.' . $ext;
        }

        // Limitar longitud
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 195 - strlen($ext)) . '.' . $ext;
        }

        return $filename;
    }

    /**
     * Sanitizar path
     */
    private function sanitize_path($path)
    {
        $path = str_replace('\\', '/', $path);
        $previous = null;

        while ($previous !== $path) {
            $previous = $path;
            $path = str_replace('../', '', $path);
            $path = str_replace('/./', '/', $path);
            $path = preg_replace('#/+#', '/', $path);
        }

        $path = ltrim($path, '/');
        return rtrim($path, '/') . '/';
    }

    private function getChunkParams($custom_filename)
    {
        return [
            'identifier' => $this->get_param('resumableIdentifier'),
            'filename' => $this->sanitize_filename($this->get_param('resumableFilename')),
            'chunk_number' => (int) $this->get_param('resumableChunkNumber'),
            'total_chunks' => (int) $this->get_param('resumableTotalChunks'),
            'total_size' => (int) $this->get_param('resumableTotalSize'),
            'custom_filename' => $custom_filename ? $this->sanitize_filename($custom_filename) : null,
        ];
    }

    private function validateChunkRequest($filename, $total_size)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!empty($this->allowed_extensions) && !in_array($ext, $this->allowed_extensions)) {
            $this->log_event('INVALID_EXTENSION', [
                'extension' => $ext,
                'filename' => $filename
            ]);
            return 'Tipo de archivo no permitido: ' . $ext;
        }

        if ($total_size > $this->max_file_size) {
            $max_mb = round($this->max_file_size / 1024 / 1024);
            return "El archivo excede el tamaño máximo de {$max_mb}MB";
        }

        $file = $this->request->files->get('file');
        if (!$file || !$file->isValid()) {
            $error_message = $file ? $file->getErrorMessage() : 'No se subió ningún archivo';
            return 'Error al recibir el chunk: ' . $error_message;
        }

        return null;
    }

    private function finalizeUpload($identifier, $total_chunks, $filename, $custom_filename)
    {
        $final_filename = $custom_filename ?: $filename;
        $final_filename = $this->sanitize_filename($final_filename);
        $final_path = $this->upload_dir . $final_filename;

        if (!$this->combine_chunks($identifier, $total_chunks, $final_path)) {
            return $this->error_response('Error al combinar los chunks');
        }

        $this->cleanup_chunks($identifier);
        if (!$this->validate_final_file($final_path)) {
            @unlink($final_path);
            return $this->error_response('El archivo no pasó la validación de seguridad');
        }

        $result = [
            'success' => true,
            'complete' => true,
            'file_path' => $final_path,
            'filename' => $final_filename,
            'filesize' => filesize($final_path),
            'message' => 'Archivo subido correctamente'
        ];

        $callbackResult = $this->executeOnCompleteCallback($final_path, $final_filename, $result['filesize']);
        if ($callbackResult === false) {
            @unlink($final_path);
            return $this->error_response($this->last_error);
        }
        if (is_array($callbackResult)) {
            $result = array_merge($result, $callbackResult);
        }

        $this->log_event('UPLOAD_COMPLETE', [
            'filename' => $final_filename,
            'size' => $result['filesize'],
            'context' => $this->context_id
        ], 'INFO');

        return $result;
    }

    private function executeOnCompleteCallback($final_path, $final_filename, $filesize)
    {
        if ($this->on_complete_callback === null) {
            return null;
        }

        try {
            return call_user_func(
                $this->on_complete_callback,
                $final_path,
                $final_filename,
                $filesize,
                $this->get_custom_params(),
                $this->user_nick
            );
        } catch (\Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    private function cleanup_orphan_directory($dir, $threshold, &$count)
    {
        $files = glob($dir . '/*');
        $all_old = true;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (filemtime($file) < $threshold) {
                @unlink($file);
                $count++;
            } else {
                $all_old = false;
            }
        }

        return $all_old && count(glob($dir . '/*')) === 0;
    }

    /**
     * Obtener parámetro de request
     */
    protected function get_param($name, $default = '')
    {
        return $this->request->get($name, $default);
    }

    /**
     * Obtener parámetros personalizados
     * @return array
     */
    protected function get_custom_params()
    {
        return [
            'version' => $this->get_param('version'),
            'custom_filename' => $this->get_param('custom_filename'),
        ];
    }

    /**
     * Asegurar que un directorio existe
     */
    private function ensure_directory($path)
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true)) {
                throw new \Exception("No se pudo crear el directorio: {$path}");
            }
        }

        if (!is_writable($path)) {
            throw new \Exception("El directorio no es escribible: {$path}");
        }
    }

    /**
     * Generar respuesta de error
     */
    private function error_response($message)
    {
        $this->last_error = $message;
        http_response_code(400);
        return [
            'success' => false,
            'complete' => false,
            'message' => $message
        ];
    }

    /**
     * Registrar evento (informativo/seguridad)
     */
    private function log_event($event_type, $data = [], $level = 'WARNING')
    {
        $data['user'] = $this->user_nick;
        $data['ip'] = $this->request->getClientIp();

        $message = sprintf(
            '[fs_secure_chunked_upload] %s: %s - %s',
            $level,
            $event_type,
            json_encode($data)
        );

        error_log($message);
    }

    // =========================================
    // MÉTODOS PÚBLICOS DE CONFIGURACIÓN
    // =========================================

    /**
     * Establecer callback cuando se complete la subida
     * 
     * @param callable $callback Función: ($final_path, $filename, $filesize, $params, $user)
     * @return self
     */
    public function on_complete(callable $callback)
    {
        $this->on_complete_callback = $callback;
        return $this;
    }

    /**
     * Obtener último error
     * 
     * @return string
     */
    public function get_last_error()
    {
        return $this->last_error;
    }

    /**
     * Verificar si una request es de subida por chunks
     * 
     * @return bool
     */
    public static function is_chunk_request()
    {
        $request = \FSFramework\Core\Kernel::request();
        return $request->get('resumableIdentifier') !== null;
    }

    /**
     * Enviar respuesta JSON
     * 
     * @param array $data
     */
    public static function send_json_response($data)
    {
        $response = new \Symfony\Component\HttpFoundation\JsonResponse($data);
        $response->send();
        exit;
    }
}
