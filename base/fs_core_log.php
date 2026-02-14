<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2017-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

// Cargar Monolog si está disponible via Composer
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Ya cargado por index.php
}

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Sistema de logging unificado para FSFramework
 * 
 * Mantiene compatibilidad con el sistema legacy mientras añade
 * soporte opcional para Monolog (PSR-3).
 * 
 * Uso básico (legacy):
 *   $log = new fs_core_log('mi_controlador');
 *   $log->new_message('Operación completada');
 *   $log->new_error('Error en la operación');
 * 
 * Uso con niveles (nuevo):
 *   $log->debug('Información de depuración', ['var' => $value]);
 *   $log->info('Usuario logueado', ['nick' => $nick]);
 *   $log->warning('Recurso casi agotado');
 *   $log->error('Error crítico', ['exception' => $e]);
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_core_log
{
    private const LOG_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Nombre del controlador que inicia este log.
     * @var string
     */
    private static $controller_name;

    /**
     * Array de mensajes.
     * @var array
     */
    private static $data_log;

    /**
     * Usuario que ha iniciado sesión.
     * @var string
     */
    private static $user_nick;

    /**
     * Logger externo (Monolog u otro PSR-3)
     * @var LoggerInterface|null
     */
    private static $externalLogger = null;

    /**
     * Si el logging a archivo está habilitado
     * @var bool
     */
    private static $fileLoggingEnabled = false;

    /**
     * Ruta del archivo de log
     * @var string
     */
    private static $logFilePath = '';

    /**
     * Nivel mínimo de log (DEBUG, INFO, WARNING, ERROR)
     * @var string
     */
    private static $minLevel = 'DEBUG';

    /**
     * Mapeo de niveles a prioridad numérica
     * @var array
     */
    private static $levelPriority = [
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600,
    ];

    /**
     * 
     * @param string $controller_name
     */
    public function __construct($controller_name = NULL)
    {
        if (!isset(self::$data_log)) {
            self::$controller_name = $controller_name;
            self::$data_log = [];
            
            // Configurar logging a archivo si está definido
            if (defined('FS_LOG_FILE') && FS_LOG_FILE) {
                self::$fileLoggingEnabled = true;
                self::$logFilePath = FS_LOG_FILE;
            } elseif (defined('FS_FOLDER')) {
                self::$logFilePath = FS_FOLDER . '/tmp/fs_framework.log';
            }
            
            // Configurar nivel mínimo
            if (defined('FS_LOG_LEVEL')) {
                self::$minLevel = strtoupper(FS_LOG_LEVEL);
            }
            
            // Intentar configurar Monolog si está disponible
            self::initMonolog();
        }
    }

    /**
     * Inicializa Monolog si está disponible
     * 
     * @return void
     */
    private static function initMonolog()
    {
        if (self::$externalLogger !== null || !class_exists('Monolog\\Logger')) {
            return;
        }

        try {
            $logger = new \Monolog\Logger('fsframework');
            self::attachFileHandler($logger);
            self::attachSyslogHandler($logger);
            self::$externalLogger = $logger;
        } catch (Exception $e) {
            // Si falla Monolog, continuamos sin él
            error_log('fs_core_log: Error inicializando Monolog: ' . $e->getMessage());
        }
    }

    private static function attachFileHandler($logger)
    {
        if (!self::$fileLoggingEnabled && !defined('FS_LOG_FILE')) {
            return;
        }

        $logFile = defined('FS_LOG_FILE') ? FS_LOG_FILE : self::$logFilePath;
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (!is_writable($logDir) && !is_writable($logFile)) {
            return;
        }

        $handler = new \Monolog\Handler\RotatingFileHandler(
            $logFile,
            defined('FS_LOG_MAX_FILES') ? FS_LOG_MAX_FILES : 7,
            self::getMonologLevel()
        );
        $handler->setFormatter(new \Monolog\Formatter\LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            self::LOG_DATETIME_FORMAT
        ));
        $logger->pushHandler($handler);
    }

    private static function attachSyslogHandler($logger)
    {
        if (!defined('FS_DEBUG') || FS_DEBUG || !class_exists('Monolog\\Handler\\SyslogHandler')) {
            return;
        }

        $syslogHandler = new \Monolog\Handler\SyslogHandler(
            'fsframework',
            LOG_USER,
            \Monolog\Logger::ERROR
        );
        $logger->pushHandler($syslogHandler);
    }

    /**
     * Obtiene el nivel de Monolog correspondiente
     * 
     * @return int
     */
    private static function getMonologLevel()
    {
        if (!class_exists('Monolog\\Logger')) {
            return 100; // DEBUG
        }

        $levelMap = [
            'DEBUG' => \Monolog\Logger::DEBUG,
            'INFO' => \Monolog\Logger::INFO,
            'NOTICE' => \Monolog\Logger::NOTICE,
            'WARNING' => \Monolog\Logger::WARNING,
            'ERROR' => \Monolog\Logger::ERROR,
            'CRITICAL' => \Monolog\Logger::CRITICAL,
            'ALERT' => \Monolog\Logger::ALERT,
            'EMERGENCY' => \Monolog\Logger::EMERGENCY,
        ];

        return isset($levelMap[self::$minLevel]) ? $levelMap[self::$minLevel] : \Monolog\Logger::DEBUG;
    }

    /**
     * Establece un logger externo (PSR-3)
     * 
     * @param LoggerInterface $logger
     * @return void
     */
    public static function setLogger($logger)
    {
        if ($logger instanceof LoggerInterface) {
            self::$externalLogger = $logger;
        }
    }

    /**
     * Obtiene el logger externo
     * 
     * @return LoggerInterface|null
     */
    public static function getLogger()
    {
        return self::$externalLogger;
    }

    /**
     * Habilita el logging a archivo
     * 
     * @param string $filePath Ruta del archivo
     * @return void
     */
    public static function enableFileLogging($filePath)
    {
        self::$fileLoggingEnabled = true;
        self::$logFilePath = $filePath;
    }

    /**
     * Deshabilita el logging a archivo
     * 
     * @return void
     */
    public static function disableFileLogging()
    {
        self::$fileLoggingEnabled = false;
    }

    /**
     * Establece el nivel mínimo de log
     * 
     * @param string $level DEBUG, INFO, WARNING, ERROR, etc.
     * @return void
     */
    public static function setMinLevel($level)
    {
        self::$minLevel = strtoupper($level);
    }

    /**
     * Verifica si un nivel debe ser logueado
     * 
     * @param string $level
     * @return bool
     */
    private function shouldLog($level)
    {
        $level = strtoupper($level);
        $minPriority = isset(self::$levelPriority[self::$minLevel]) ? self::$levelPriority[self::$minLevel] : 100;
        $currentPriority = isset(self::$levelPriority[$level]) ? self::$levelPriority[$level] : 100;
        
        return $currentPriority >= $minPriority;
    }

    public function clean_advices()
    {
        $this->clean('advices');
    }

    public function clean_errors()
    {
        $this->clean('errors');
    }

    public function clean_messages()
    {
        $this->clean('messages');
    }

    public function clean_sql_history()
    {
        $this->clean('sql');
    }

    public function clean_to_save()
    {
        $this->clean('save');
    }

    /**
     * 
     * @return string
     */
    public function controller_name()
    {
        return self::$controller_name;
    }

    /**
     * Devuelve el listado de consejos a mostrar al usuario.
     * @return array
     */
    public function get_advices()
    {
        return $this->read('advices');
    }

    /**
     * Devuelve el listado de errores a mostrar al usuario.
     * @return array
     */
    public function get_errors()
    {
        return $this->read('errors');
    }

    /**
     * Devuelve el listado de mensajes a mostrar al usuario.
     * @return array
     */
    public function get_messages()
    {
        return $this->read('messages');
    }

    /**
     * Devuelve el historial de consultas SQL.
     * @return array
     */
    public function get_sql_history()
    {
        return $this->read('sql');
    }

    /**
     * Devuelve la lista de mensajes a guardar.
     * @return array
     */
    public function get_to_save()
    {
        return $this->read('save', true);
    }

    /**
     * Añade un consejo al listado.
     * @param string $msg
     * @param array  $context
     */
    public function new_advice($msg, $context = [])
    {
        $this->log($msg, 'advices', $context);
    }

    /**
     * Añade un mensaje de error al listado.
     * @param string $msg
     * @param array  $context
     */
    public function new_error($msg, $context = [])
    {
        $this->log($msg, 'errors', $context);
    }

    /**
     * Añade un mensaje al listado.
     * @param string $msg
     * @param array  $context
     */
    public function new_message($msg, $context = [])
    {
        $this->log($msg, 'messages', $context);
    }

    /**
     * Añade una consulta SQL al historial.
     * @param string $sql
     */
    public function new_sql($sql)
    {
        $this->log($sql, 'sql');
    }

    /**
     * Añade un mensaje para guardar después con el fs_log_manager.
     * @param string $msg
     * @param string $type
     * @param bool   $alert
     * @param array  $context
     */
    public function save($msg, $type = 'error', $alert = FALSE, $context = [])
    {
        $context['alert'] = $alert;
        $context['type'] = $type;
        $this->log($msg, 'save', $context);
    }

    /**
     * 
     * @param string $nick
     */
    public function set_user_nick($nick)
    {
        self::$user_nick = $nick;
    }

    /**
     * 
     * @return string
     */
    public function user_nick()
    {
        return self::$user_nick;
    }

    /**
     * 
     * @param string $channel
     */
    private function clean($channel)
    {
        foreach (self::$data_log as $key => $value) {
            if ($value['channel'] === $channel) {
                unset(self::$data_log[$key]);
            }
        }
    }

    /**
     * 
     * @param string $msg
     * @param string $channel
     * @param array  $context
     */
    private function log($msg, $channel, $context = [])
    {
        self::$data_log[] = [
            'channel' => $channel,
            'context' => $context,
            'message' => $msg,
            'time' => time(),
        ];
    }

    /**
     * 
     * @param string $channel
     * @return array
     */
    private function read($channel, $full = false)
    {
        $messages = [];
        foreach (self::$data_log as $data) {
            if ($data['channel'] === $channel) {
                $messages[] = $full ? $data : $data['message'];
            }
        }

        return $messages;
    }

    // =========================================================================
    // NUEVOS MÉTODOS PSR-3 COMPATIBLES
    // =========================================================================

    /**
     * Log de nivel DEBUG
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function debug($message, $context = [])
    {
        $this->logWithLevel('DEBUG', $message, $context);
    }

    /**
     * Log de nivel INFO
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function info($message, $context = [])
    {
        $this->logWithLevel('INFO', $message, $context);
        $this->new_message($message, $context);
    }

    /**
     * Log de nivel NOTICE
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function notice($message, $context = [])
    {
        $this->logWithLevel('NOTICE', $message, $context);
        $this->new_advice($message, $context);
    }

    /**
     * Log de nivel WARNING
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function warning($message, $context = [])
    {
        $this->logWithLevel('WARNING', $message, $context);
        $this->new_advice($message, $context);
    }

    /**
     * Log de nivel ERROR
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function error($message, $context = [])
    {
        $this->logWithLevel('ERROR', $message, $context);
        $this->new_error($message, $context);
    }

    /**
     * Log de nivel CRITICAL
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function critical($message, $context = [])
    {
        $this->logWithLevel('CRITICAL', $message, $context);
        $this->new_error('[CRITICAL] ' . $message, $context);
    }

    /**
     * Log de nivel ALERT
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function alert($message, $context = [])
    {
        $this->logWithLevel('ALERT', $message, $context);
        $this->new_error('[ALERT] ' . $message, $context);
    }

    /**
     * Log de nivel EMERGENCY
     * 
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    public function emergency($message, $context = [])
    {
        $this->logWithLevel('EMERGENCY', $message, $context);
        $this->new_error('[EMERGENCY] ' . $message, $context);
    }

    /**
     * Log genérico con nivel
     * 
     * @param string $level Nivel de log
     * @param string $message Mensaje
     * @param array $context Contexto adicional
     * @return void
     */
    private function logWithLevel($level, $message, $context = [])
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        // Añadir información de contexto
        $context['controller'] = self::$controller_name;
        $context['user'] = self::$user_nick;
        $context['timestamp'] = date('Y-m-d H:i:s');

        // Delegar a logger externo si existe
        if (self::$externalLogger !== null) {
            $method = strtolower($level);
            if (method_exists(self::$externalLogger, $method)) {
                self::$externalLogger->$method($message, $context);
            } else {
                self::$externalLogger->log($level, $message, $context);
            }
        }

        // Log a archivo si está habilitado y no hay Monolog
        if (self::$fileLoggingEnabled && self::$externalLogger === null) {
            $this->writeToFile($level, $message, $context);
        }
    }

    /**
     * Escribe directamente al archivo de log
     * 
     * @param string $level Nivel
     * @param string $message Mensaje
     * @param array $context Contexto
     * @return void
     */
    private function writeToFile($level, $message, $context = [])
    {
        if (empty(self::$logFilePath)) {
            return;
        }

        $logDir = dirname(self::$logFilePath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (!is_writable($logDir) && !is_writable(self::$logFilePath)) {
            return;
        }

        // Formatear contexto
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            'fsframework',
            $level,
            $message,
            $contextStr
        );

        @file_put_contents(self::$logFilePath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Obtiene todos los logs del nivel especificado
     * 
     * @param string $level Nivel de log
     * @return array
     */
    public function getByLevel($level)
    {
        $level = strtoupper($level);
        $messages = [];
        
        foreach (self::$data_log as $data) {
            if (isset($data['context']['level']) && strtoupper($data['context']['level']) === $level) {
                $messages[] = $data;
            }
        }
        
        return $messages;
    }

    /**
     * Limpia todos los logs
     * 
     * @return void
     */
    public function clear()
    {
        self::$data_log = [];
    }

    /**
     * Obtiene estadísticas de los logs
     * 
     * @return array
     */
    public function getStats()
    {
        $stats = [
            'total' => count(self::$data_log),
            'errors' => count($this->get_errors()),
            'messages' => count($this->get_messages()),
            'advices' => count($this->get_advices()),
            'sql_queries' => count($this->get_sql_history()),
        ];
        
        return $stats;
    }

    /**
     * Exporta los logs a JSON
     * 
     * @return string
     */
    public function toJson()
    {
        return json_encode(self::$data_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Exporta los logs a array
     * 
     * @return array
     */
    public function toArray()
    {
        return self::$data_log;
    }
}
