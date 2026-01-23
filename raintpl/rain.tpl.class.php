<?php
/**
 * RainTPL 4.0 Adapter for FSFramework
 * -----------------------------------
 * Este archivo adapta RainTPL 4.0 para mantener compatibilidad con el sistema
 * legacy de FSFramework/FacturaScripts.
 * 
 * Mantiene la misma interfaz que la versión 2.x pero usa el motor 4.0 internamente.
 * 
 * @version 4.0-compat
 */

// Cargar el autoloader de RainTPL 4
require_once __DIR__ . '/autoload.php';

/**
 * Clase adaptadora que extiende Rain\Tpl para compatibilidad con FSFramework.
 * Mantiene las propiedades estáticas y métodos que el sistema espera.
 */
class RainTPL extends \Rain\Tpl
{
    // -------------------------
    //    LEGACY CONFIGURATION (compatibilidad)
    // -------------------------

    /**
     * Template directory (legacy - se convierte a array para multi-directorio)
     * @var string
     */
    public static $tpl_dir = 'view/';

    /**
     * Cache directory
     * @var string
     */
    public static $cache_dir = null;

    /**
     * Template base URL
     * @var string
     */
    public static $base_url = null;

    /**
     * Template extension
     * @var string
     */
    public static $tpl_ext = 'html';

    /**
     * Path replace (ignorado en v4, pero mantenido por compatibilidad)
     * @var bool
     */
    public static $path_replace = true;

    /**
     * Check template update
     * @var bool
     */
    public static $check_template_update = true;

    /**
     * PHP tags enabled
     * @var bool
     */
    public static $php_enabled = false;

    /**
     * Debug mode
     * @var bool
     */
    public static $debug = false;

    /**
     * Configuración aplicada (para tracking)
     * @var array
     */
    private static $legacy_config = [];

    /**
     * Configura RainTPL con compatibilidad legacy.
     * Acepta los mismos parámetros que la versión 2.x.
     *
     * @param string|array $setting Nombre del setting o array asociativo
     * @param mixed $value Valor del setting (si $setting es string)
     */
    public static function configure($setting, $value = null)
    {
        if (is_array($setting)) {
            foreach ($setting as $key => $val) {
                self::configure($key, $val);
            }
            return;
        }

        // Guardar configuración legacy
        self::$legacy_config[$setting] = $value;

        // Mapear a propiedades estáticas legacy
        switch ($setting) {
            case 'tpl_dir':
                self::$tpl_dir = $value;
                break;
            case 'cache_dir':
                self::$cache_dir = self::addTrailingSlash($value);
                break;
            case 'base_url':
                self::$base_url = $value;
                break;
            case 'tpl_ext':
                self::$tpl_ext = $value;
                break;
            case 'path_replace':
                self::$path_replace = $value;
                break;
            case 'check_template_update':
                self::$check_template_update = $value;
                break;
            case 'php_enabled':
                self::$php_enabled = $value;
                break;
            case 'debug':
                self::$debug = $value;
                break;
        }

        // Aplicar a Rain\Tpl (motor v4)
        self::applyToParent();
    }

    /**
     * Aplica la configuración al motor Rain\Tpl padre.
     */
    private static function applyToParent()
    {
        // Construir array de directorios de templates en orden de prioridad
        $tpl_dirs = self::buildTemplateDirectories();

        // Aplicar configuración al motor padre
        parent::configure('tpl_dir', $tpl_dirs);
        parent::configure('cache_dir', self::$cache_dir ?: 'tmp/');
        parent::configure('tpl_ext', self::$tpl_ext);
        parent::configure('base_url', self::$base_url ?: '');
        parent::configure('php_enabled', self::$php_enabled);
        parent::configure('debug', self::$debug || self::$check_template_update);
        parent::configure('auto_escape', false);  // Desactivado para compatibilidad con templates existentes
        parent::configure('sandbox', false);      // Desactivado para compatibilidad
    }

    /**
     * Construye el array de directorios de templates en orden de prioridad.
     * Respeta el sistema de overrides de vistas por plugins.
     *
     * @return array Lista de directorios de templates
     */
    private static function buildTemplateDirectories()
    {
        $dirs = [];

        // Primero: plugins activos en orden (AdminLTE, facturacion_base, tarifario, etc.)
        if (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                $pluginViewDir = 'plugins/' . $plugin . '/view/';
                if (is_dir($pluginViewDir)) {
                    $dirs[] = $pluginViewDir;
                }
            }
        }

        // Último: directorio por defecto (view/)
        $defaultDir = self::$tpl_dir ?: 'view/';
        if (!in_array($defaultDir, $dirs)) {
            $dirs[] = $defaultDir;
        }

        return $dirs;
    }

    /**
     * Añade trailing slash si no existe.
     *
     * @param string $path
     * @return string
     */
    private static function addTrailingSlash($path)
    {
        if (empty($path)) {
            return $path;
        }
        return rtrim($path, '/\\') . '/';
    }

    /**
     * Dibuja el template.
     * Sobrescribe para asegurar que la configuración se aplica antes.
     *
     * @param string $templateFilePath Nombre del template
     * @param bool $toString Si debe retornar string o hacer echo
     * @return void|string
     */
    public function draw($templateFilePath, $toString = false)
    {
        // Asegurar que la configuración está aplicada
        self::applyToParent();

        return parent::draw($templateFilePath, $toString);
    }

    /**
     * Sobrescribe checkTemplate para compatibilidad con includes de RainTPL 2.x.
     * RainTPL 4 añade el directorio del template padre a los includes, lo cual
     * puede causar paths incorrectos (ej: "master/block/file" cuando debería ser "block/file").
     * 
     * Esta función intenta múltiples variaciones del path para encontrar el template.
     *
     * @param string $template
     * @return string Path al template compilado
     */
    protected function checkTemplate($template)
    {
        // Primero intenta con el path original
        try {
            return parent::checkTemplate($template);
        } catch (\Rain\Tpl\NotFoundException $e) {
            // Si tiene subdirectorio, intentar variaciones
            if (strpos($template, '/') !== false) {
                $alternatives = [];
                
                // 1. Solo el nombre base (ej: "master/block/file" -> "file")
                $alternatives[] = basename($template);
                
                // 2. Remover el primer segmento del path (ej: "master/block/file" -> "block/file")
                $parts = explode('/', $template);
                if (count($parts) > 2) {
                    array_shift($parts);
                    $alternatives[] = implode('/', $parts);
                }
                
                // 3. Solo el último directorio + nombre (ej: "a/b/c/file" -> "c/file")
                if (count($parts) > 1) {
                    $alternatives[] = $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1];
                }
                
                // Intentar cada alternativa
                foreach ($alternatives as $alt) {
                    try {
                        return parent::checkTemplate($alt);
                    } catch (\Rain\Tpl\NotFoundException $e2) {
                        continue;
                    }
                }
            }
            // Si nada funciona, lanzar el error original
            throw $e;
        }
    }
}

// -------------------------
// EXCEPCIONES DE COMPATIBILIDAD
// -------------------------

/**
 * Excepción legacy de RainTPL
 */
class RainTpl_Exception extends \Rain\Tpl\Exception
{
}

/**
 * Excepción de template no encontrado (legacy)
 */
class RainTpl_NotFoundException extends \Rain\Tpl\NotFoundException
{
}

/**
 * Excepción de sintaxis (legacy)
 */
class RainTpl_SyntaxException extends \Rain\Tpl\SyntaxException
{
}
