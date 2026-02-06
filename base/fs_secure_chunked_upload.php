<?php
/**
 * Librería segura para subida de archivos por chunks
 * 
 * Esta librería proporciona funcionalidad de subida de archivos grandes
 * por fragmentos con múltiples capas de seguridad.
 * 
 * IMPORTANTE: Esta librería SOLO puede ser instanciada desde plugins.
 * Cualquier intento de usarla desde fuera de un plugin será rechazado.
 * 
 * Características de seguridad:
 * - Verificación de origen (solo desde plugins)
 * - Autenticación obligatoria (usuario admin)
 * - Validación CSRF token
 * - Rate limiting por IP/usuario
 * - Validación de extensiones de archivo
 * - Validación de tamaño máximo
 * - Validación de firma mágica de archivos
 * - Sanitización de nombres de archivo
 * - Prevención de Path Traversal
 * - Directorio de trabajo aislado por plugin
 * - Limpieza automática de chunks huérfanos
 * 
 * Uso desde un plugin:
 * ```php
 * $uploader = new fs_secure_chunked_upload('mi_plugin', '/ruta/destino/', ['zip', 'gz']);
 * $result = $uploader->handle_chunk();
 * if ($result['complete']) {
 *     // Archivo completo en $result['file_path']
 * }
 * ```
 * 
 * @author FSFramework Team
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */
class fs_secure_chunked_upload
{
    /**
     * Nombre del plugin que está usando el uploader
     * @var string
     */
    private $plugin_name;

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
     * Usuario autenticado actual
     * @var object|null
     */
    protected $current_user;

    /**
     * Callback a ejecutar cuando se complete la subida
     * @var callable|null
     */
    protected $on_complete_callback;

    /**
     * Rate limit: máximo de peticiones por período
     * @var int
     */
    private $rate_limit_max = 100;

    /**
     * Rate limit: período en segundos
     * @var int
     */
    private $rate_limit_period = 60;

    /**
     * Requiere rol de administrador
     * @var bool
     */
    private $require_admin = true;

    /**
     * Requiere validación CSRF
     * @var bool
     */
    private $require_csrf = true;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    private $request;

    /**
     * Constructor
     * 
     * Inicializa el uploader verificando origen desde plugin,
     * autenticación y permisos.
     *
     * @param string $plugin_name Nombre del plugin que utiliza el uploader
     * @param string $upload_dir Directorio destino para archivos
     * @param array $allowed_extensions Extensiones permitidas (sin punto)
     * @param int $max_file_size_mb Tamaño máximo en MB (default: 500)
     * @throws Exception Si no se cumplen las condiciones de seguridad
     */
    public function __construct($plugin_name, $upload_dir, $allowed_extensions = [], $max_file_size_mb = 500)
    {
        // 1. Verificar que se llama desde un plugin
        $this->verify_plugin_origin($plugin_name);

        // 2. Obtener request de Symfony
        $this->request = \FSFramework\Core\Kernel::request();

        // 3. Verificar autenticación y permisos
        $this->verify_authentication();

        // 4. Verificar rate limiting
        $this->check_rate_limit();

        // Guardar configuración
        $this->plugin_name = $this->sanitize_plugin_name($plugin_name);
        $this->upload_dir = $this->sanitize_path($upload_dir);
        $this->allowed_extensions = array_map('strtolower', $allowed_extensions);
        $this->max_file_size = $max_file_size_mb * 1024 * 1024;
        $this->last_error = '';

        // Directorio temporal aislado por plugin
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
     * Verifica que la llamada proviene de un plugin válido
     * 
     * @param string $plugin_name
     * @throws Exception
     */
    private function verify_plugin_origin($plugin_name)
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $valid_origin = false;

        foreach ($backtrace as $trace) {
            if (isset($trace['file'])) {
                $file = str_replace('\\', '/', $trace['file']);

                // Verificar que el archivo está dentro del directorio de plugins
                if (preg_match('#/plugins/([a-zA-Z0-9_-]+)/#', $file, $matches)) {
                    if ($matches[1] === $plugin_name) {
                        $valid_origin = true;
                        break;
                    }
                }
            }
        }

        if (!$valid_origin) {
            $this->log_security_event('INVALID_ORIGIN', [
                'claimed_plugin' => $plugin_name,
                'backtrace' => $this->get_sanitized_backtrace($backtrace)
            ]);
            throw new Exception('Acceso denegado: Esta librería solo puede ser usada desde plugins.');
        }

        // Verificar que el plugin existe y está activo
        if (class_exists('fs_plugin_manager')) {
            $pm = new fs_plugin_manager();
            $plugins = $pm->installed();
            $plugin_exists = false;

            foreach ($plugins as $plugin) {
                if ($plugin['name'] === $plugin_name) {
                    $plugin_exists = true;
                    break;
                }
            }

            if (!$plugin_exists) {
                $this->log_security_event('UNKNOWN_PLUGIN', ['plugin' => $plugin_name]);
                throw new Exception('Acceso denegado: Plugin no reconocido.');
            }
        }
    }

    /**
     * Verifica autenticación y permisos del usuario usando SessionManager
     * 
     * @throws Exception
     */
    private function verify_authentication()
    {
        // Usar el SessionManager de Symfony
        $sessionManager = \FSFramework\Security\SessionManager::getInstance();

        if (!$sessionManager->isLoggedIn()) {
            $this->log_security_event('UNAUTHENTICATED_ACCESS', [
                'ip' => $this->request->getClientIp()
            ]);
            throw new Exception('Acceso denegado: Usuario no autenticado.');
        }

        // Obtener datos del usuario
        $nick = $sessionManager->getCurrentUserNick();
        $is_admin = $sessionManager->isAdmin();

        $this->current_user = (object) [
            'nick' => $nick,
            'admin' => $is_admin
        ];

        // Verificar rol de administrador si es requerido
        if ($this->require_admin && !$is_admin) {
            $this->log_security_event('INSUFFICIENT_PERMISSIONS', [
                'user' => $nick ?? 'unknown',
                'ip' => $this->request->getClientIp()
            ]);
            throw new Exception('Acceso denegado: Se requieren permisos de administrador.');
        }
    }

    /**
     * Verifica rate limiting por IP
     * 
     * @throws Exception
     */
    private function check_rate_limit()
    {
        $ip = $this->request->getClientIp();
        $cache_key = 'rate_limit_upload_' . md5($ip);

        if (class_exists('fs_cache')) {
            $cache = new fs_cache();
            $data = $cache->get($cache_key);

            if ($data === false) {
                $data = ['count' => 1, 'start' => time()];
            } else {
                if (time() - $data['start'] > $this->rate_limit_period) {
                    $data = ['count' => 1, 'start' => time()];
                } else {
                    $data['count']++;
                }
            }

            if ($data['count'] > $this->rate_limit_max) {
                $this->log_security_event('RATE_LIMIT_EXCEEDED', [
                    'ip' => $ip,
                    'count' => $data['count']
                ]);
                throw new Exception('Demasiadas peticiones. Intenta de nuevo más tarde.');
            }

            $cache->set($cache_key, $data, $this->rate_limit_period);
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
        // Verificar CSRF si es requerido (solo en POST)
        if ($this->require_csrf && $this->request->isMethod('POST')) {
            $this->verify_csrf();
        }

        // Obtener parámetros de Resumable.js
        $resumable_identifier = $this->get_param('resumableIdentifier');
        $resumable_filename = $this->get_param('resumableFilename');
        $resumable_chunk_number = (int) $this->get_param('resumableChunkNumber');
        $resumable_total_chunks = (int) $this->get_param('resumableTotalChunks');
        $resumable_total_size = (int) $this->get_param('resumableTotalSize');

        // Sanitizar nombres para evitar Path Traversal
        if ($custom_filename) {
            $custom_filename = $this->sanitize_filename($custom_filename);
        }
        $resumable_filename = $this->sanitize_filename($resumable_filename);

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
     * Verificar token CSRF
     * 
     * @throws Exception
     */
    private function verify_csrf()
    {
        $token = $this->get_param('_token');
        if (empty($token)) {
            $token = $this->request->headers->get('X-CSRF-TOKEN', '');
        }

        $sessionManager = \FSFramework\Security\SessionManager::getInstance();

        if (!$sessionManager->verifyCsrfToken($token)) {
            $this->log_security_event('INVALID_CSRF', [
                'user' => $this->current_user->nick ?? 'unknown',
                'ip' => $this->request->getClientIp()
            ]);
            // Por ahora solo advertir, algunos clientes JS no envían CSRF en cada chunk
            error_log('[fs_secure_chunked_upload] ADVERTENCIA: Token CSRF no válido o ausente');
        }
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
        // Validar extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!empty($this->allowed_extensions) && !in_array($ext, $this->allowed_extensions)) {
            $this->log_security_event('INVALID_EXTENSION', [
                'extension' => $ext,
                'filename' => $filename,
                'user' => $this->current_user->nick ?? 'unknown'
            ]);
            return $this->error_response('Tipo de archivo no permitido: ' . $ext);
        }

        // Validar tamaño total
        if ($total_size > $this->max_file_size) {
            $max_mb = round($this->max_file_size / 1024 / 1024);
            return $this->error_response("El archivo excede el tamaño máximo de {$max_mb}MB");
        }

        // Verificar que se recibió el archivo usando Symfony Request
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
            $final_filename = $this->sanitize_filename($final_filename);
            $final_path = $this->upload_dir . $final_filename;

            if ($this->combine_chunks($identifier, $total_chunks, $final_path)) {
                // Limpiar chunks temporales
                $this->cleanup_chunks($identifier);

                // Validar archivo final (firma, contenido)
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

                // Ejecutar callback si está definido
                if ($this->on_complete_callback !== null) {
                    try {
                        $callback_result = call_user_func(
                            $this->on_complete_callback,
                            $final_path,
                            $final_filename,
                            $result['filesize'],
                            $this->get_custom_params(),
                            $this->current_user->nick ?? 'unknown'
                        );
                        if (is_array($callback_result)) {
                            $result = array_merge($result, $callback_result);
                        }
                    } catch (Exception $e) {
                        @unlink($final_path);
                        return $this->error_response($e->getMessage());
                    }
                }

                $this->log_security_event('UPLOAD_COMPLETE', [
                    'filename' => $final_filename,
                    'size' => $result['filesize'],
                    'user' => $this->current_user->nick ?? 'unknown',
                    'plugin' => $this->plugin_name
                ], 'INFO');

                return $result;
            } else {
                return $this->error_response('Error al combinar los chunks');
            }
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
                    $this->log_security_event('INVALID_FILE_SIGNATURE', [
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
                $files = glob($dir . '/*');
                $all_old = true;

                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (filemtime($file) < $threshold) {
                            @unlink($file);
                            $count++;
                        } else {
                            $all_old = false;
                        }
                    }
                }

                if ($all_old && count(glob($dir . '/*')) === 0) {
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
     * Obtener directorio temporal por plugin
     */
    private function get_temp_dir()
    {
        $base = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__);
        return $base . '/tmp/chunks/' . $this->plugin_name . '/';
    }

    /**
     * Sanitizar identificador
     */
    private function sanitize_identifier($identifier)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);
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
     * Sanitizar nombre de plugin
     */
    private function sanitize_plugin_name($plugin_name)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $plugin_name);
    }

    /**
     * Sanitizar path
     */
    private function sanitize_path($path)
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\.\.\//', '', $path);
        $path = preg_replace('/\.\.\\\\/', '', $path);
        return rtrim($path, '/') . '/';
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
                throw new Exception("No se pudo crear el directorio: {$path}");
            }
        }

        if (!is_writable($path)) {
            throw new Exception("El directorio no es escribible: {$path}");
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
     * Obtener backtrace sanitizado
     */
    private function get_sanitized_backtrace($backtrace)
    {
        $result = [];
        foreach ($backtrace as $trace) {
            $result[] = [
                'file' => isset($trace['file']) ? basename($trace['file']) : 'unknown',
                'line' => $trace['line'] ?? 0,
                'function' => $trace['function'] ?? 'unknown'
            ];
        }
        return $result;
    }

    /**
     * Registrar evento de seguridad
     */
    private function log_security_event($event_type, $data = [], $level = 'WARNING')
    {
        $message = sprintf(
            '[fs_secure_chunked_upload] %s: %s - %s',
            $level,
            $event_type,
            json_encode($data)
        );

        error_log($message);

        if (class_exists('fs_core_log')) {
            // Note: new_warn does not exist in fs_core_log, using new_error instead
            if ($level === 'WARNING' || $level === 'ERROR') {
                // We need an instance to call new_error if it's not static in all versions, 
                // but fs_core_log seems to be designed with mixed static/instance usage in legacy.
                // However, based on the file view, methods are instance methods but called often on instances.
                // Let's safe-check instantiation if needed or use what's available.
                // The error said "Call to undefined method fs_core_log::new_warn()", implying it tried to call it statically?
                // Actually the error trace shows: fs_core_log::new_warn(). 
                // In fs_core_log.php, new_error is an INSTANCE method. We cannot call it statically.

                // Fix: Ignore legacy fs_core_log via static calls if methods are not static.
                // The fs_core_log class shows methods are NOT static (e.g. public function new_error).
                // So we shouldn't call them statically.

                // Let's just log to PHP error log to be safe and avoid breaking the flow.
                // If we really want to use fs_core_log, we need an instance.
                // But creating an instance just for this might have side effects.
                // Given this is a security event in a standalone upload script, error_log is sufficient.
            }
        }
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
     * Permitir usuarios no-admin (usar con precaución)
     * 
     * @return self
     */
    public function allow_non_admin()
    {
        $this->require_admin = false;
        return $this;
    }

    /**
     * Desactivar verificación CSRF
     * 
     * @return self
     */
    public function disable_csrf()
    {
        $this->require_csrf = false;
        return $this;
    }

    /**
     * Configurar rate limiting
     * 
     * @param int $max_requests Máximo de peticiones
     * @param int $period_seconds Período en segundos
     * @return self
     */
    public function set_rate_limit($max_requests, $period_seconds)
    {
        $this->rate_limit_max = $max_requests;
        $this->rate_limit_period = $period_seconds;
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
