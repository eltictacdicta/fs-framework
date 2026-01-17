<?php
/**
 * Helper para manejar subidas de archivos grandes por chunks usando Resumable.js
 *
 * Uso:
 * $uploader = new fs_chunked_upload('/ruta/destino/', array('xlsx', 'xls'));
 * $result = $uploader->handle_chunk();
 *
 * if ($result['complete']) {
 * // Archivo completo, procesar $result['file_path']
 * }
 *
 * @author FSFramework
 */
class fs_chunked_upload
{
    /**
     * Directorio destino para los archivos
     * @var string
     */
    private $upload_dir;

    /**
     * Directorio temporal para chunks
     * @var string
     */
    private $temp_dir;

    /**
     * Extensiones de archivo permitidas
     * @var array
     */
    private $allowed_extensions;

    /**
     * Tamaño máximo de archivo en bytes
     * @var int
     */
    private $max_file_size;

    /**
     * Último mensaje de error
     * @var string
     */
    private $last_error;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    private $request;

    /**
     * Constructor
     *
     * @param string $upload_dir Directorio destino
     * @param array $allowed_extensions Extensiones permitidas
     * @param int $max_file_size_mb Tamaño máximo en MB (default: 500)
     */
    public function __construct($upload_dir, $allowed_extensions = array(), $max_file_size_mb = 500)
    {
        // Verificar acceso: solo administradores
        $this->check_access();

        $this->upload_dir = rtrim($upload_dir, '/') . '/';
        $this->temp_dir = sys_get_temp_dir() . '/fs_chunks/';
        $this->allowed_extensions = array_map('strtolower', $allowed_extensions);
        $this->max_file_size = $max_file_size_mb * 1024 * 1024;
        $this->last_error = '';

        // Obtener request de Symfony
        $this->request = \FSFramework\Core\Kernel::request();

        // Crear directorios si no existen
        $this->ensure_directory($this->upload_dir);
        $this->ensure_directory($this->temp_dir);
    }

    /**
     * Verificar si el usuario tiene permiso para subir archivos
     */
    private function check_access()
    {
        // Cargar dependencias necesarias si no existen
        if (!class_exists('fs_db2')) {
            require_once __DIR__ . '/fs_db2.php';
        }
        if (!class_exists('fs_login')) {
            require_once __DIR__ . '/fs_login.php';
        }
        if (!class_exists('fs_user')) {
            if (!class_exists('fs_model')) {
                require_once __DIR__ . '/fs_model.php';
            }
            // Intentar cargar modelo de usuario
            if (file_exists(__DIR__ . '/../model/fs_user.php')) {
                require_once __DIR__ . '/../model/fs_user.php';
            } elseif (function_exists('require_model')) {
                require_model('fs_user');
            }
        }

        // Conectar BD si no está conectada
        $db = new fs_db2();
        if (!$db->connected()) {
            $db->connect();
        }

        // Verificar login
        $user = new fs_user();
        $login = new fs_login();

        // Intentar loguear con cookie si no está logueado
        if (!$login->log_in($user)) {
            $this->deny_access('Usuario no identificado');
        }

        // Verificar usuario y si es administrador
        if (!$user->logged_on || !$user->admin) {
            $this->deny_access('Acceso denegado: Se requieren permisos de administrador');
        }
    }

    /**
     * Denegar acceso y salir
     */
    private function deny_access($message)
    {
        http_response_code(403);
        self::send_json_response(array(
            'success' => false,
            'complete' => false,
            'message' => $message
        ));
    }


    /**
     * Manejar un chunk subido
     *
     * @param string|null $custom_filename Nombre personalizado para el archivo final
     * @return array Resultado con 'success', 'complete', 'file_path', 'message'
     */
    public function handle_chunk($custom_filename = null)
    {
        // Obtener parámetros de Resumable.js
        $resumable_identifier = $this->get_param('resumableIdentifier');
        $resumable_filename = $this->get_param('resumableFilename');
        $resumable_chunk_number = (int) $this->get_param('resumableChunkNumber');
        $resumable_total_chunks = (int) $this->get_param('resumableTotalChunks');
        $resumable_total_size = (int) $this->get_param('resumableTotalSize');

        // Sanitizar el nombre del archivo para evitar Path Traversal
        if ($custom_filename) {
            $custom_filename = basename($custom_filename);
        }
        $resumable_filename = basename($resumable_filename);

        // Manejar petición GET (verificar si chunk existe)
        if ($this->request->isMethod('GET')) {
            return $this->check_chunk_exists($resumable_identifier, $resumable_chunk_number);
        }

        // Manejar petición POST (subir chunk)
        if ($this->request->isMethod('POST')) {
            return $this->receive_chunk(
                $resumable_identifier,
                $resumable_filename,
                $resumable_chunk_number,
                $resumable_total_chunks,
                $resumable_total_size,
                $custom_filename
            );
        }

        return $this->error_response('Método no soportado');
    }

    /**
     * Verificar si un chunk ya existe (para reanudar subidas)
     */
    private function check_chunk_exists($identifier, $chunk_number)
    {
        $chunk_file = $this->get_chunk_path($identifier, $chunk_number);

        if (file_exists($chunk_file)) {
            return array(
                'success' => true,
                'complete' => false,
                'message' => 'Chunk exists'
            );
        }

        // Retornar 404 para indicar que no existe
        http_response_code(404);
        return array(
            'success' => false,
            'complete' => false,
            'message' => 'Chunk not found'
        );
    }

    /**
     * Recibir y guardar un chunk
     */
    private function receive_chunk($identifier, $filename, $chunk_number, $total_chunks, $total_size, $custom_filename)
    {
        // Validar extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!empty($this->allowed_extensions) && !in_array($ext, $this->allowed_extensions)) {
            return $this->error_response('Tipo de archivo no permitido: ' . $ext);
        }

        // Validar tamaño total
        if ($total_size > $this->max_file_size) {
            $max_mb = round($this->max_file_size / 1024 / 1024);
            return $this->error_response("El archivo excede el tamaño máximo de {$max_mb}MB");
        }

        // Verificar que se recibió el archivo
        $file = $this->request->files->get('file');
        if (!$file || !$file->isValid()) {
            $error_message = $file ? $file->getErrorMessage() : 'No se subió ningún archivo';
            return $this->error_response('Error al recibir el chunk: ' . $error_message);
        }

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

        // Verificar si todos los chunks están completos
        if ($this->all_chunks_received($identifier, $total_chunks)) {
            // Combinar chunks
            $final_filename = $custom_filename ?: $filename;
            // Asegurarnos de que final_path esté dentro de upload_dir
            $final_filename = basename($final_filename);
            $final_path = $this->upload_dir . $final_filename;

            if ($this->combine_chunks($identifier, $total_chunks, $final_path)) {
                // Limpiar chunks temporales
                $this->cleanup_chunks($identifier);

                return array(
                    'success' => true,
                    'complete' => true,
                    'file_path' => $final_path,
                    'filename' => $final_filename,
                    'filesize' => filesize($final_path),
                    'message' => 'Archivo subido correctamente'
                );
            } else {
                return $this->error_response('Error al combinar los chunks');
            }
        }

        // Chunk recibido pero archivo incompleto
        return array(
            'success' => true,
            'complete' => false,
            'chunk' => $chunk_number,
            'total' => $total_chunks,
            'message' => "Chunk {$chunk_number}/{$total_chunks} recibido"
        );
    }

    /**
     * Verificar si todos los chunks fueron recibidos
     */
    private function all_chunks_received($identifier, $total_chunks)
    {
        for ($i = 1; $i <= $total_chunks; $i++) {
            $chunk_file = $this->get_chunk_path($identifier, $i);
            if (!file_exists($chunk_file)) {
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
     * Limpiar chunks temporales
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
     * Obtener ruta de un chunk
     */
    private function get_chunk_path($identifier, $chunk_number)
    {
        $safe_id = $this->sanitize_identifier($identifier);
        return $this->temp_dir . $safe_id . '/chunk_' . str_pad($chunk_number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Sanitizar identificador de archivo
     */
    private function sanitize_identifier($identifier)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);
    }

    /**
     * Obtener parámetro de GET o POST usando Symfony Request
     */
    private function get_param($name, $default = '')
    {
        return $this->request->get($name, $default);
    }

    /**
     * Asegurar que un directorio existe
     */
    private function ensure_directory($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Generar respuesta de error
     */
    private function error_response($message)
    {
        $this->last_error = $message;
        http_response_code(400);
        return array(
            'success' => false,
            'complete' => false,
            'message' => $message
        );
    }

    /**
     * Obtener último error
     */
    public function get_last_error()
    {
        return $this->last_error;
    }

    /**
     * Enviar respuesta JSON
     */
    public static function send_json_response($data)
    {
        $response = new \Symfony\Component\HttpFoundation\JsonResponse($data);
        $response->send();
        exit;
    }
}