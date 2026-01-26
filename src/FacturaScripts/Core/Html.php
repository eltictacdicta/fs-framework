<?php

namespace FacturaScripts\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use FacturaScripts\Core\Template\RainToTwig;
use FSFramework\Translation\FSTranslator;
use FSFramework\Twig\TranslationExtension;
use FSFramework\Security\CsrfManager;

/**
 * Bridge for HTML rendering (RainTPL + Twig)
 * 
 * Supports:
 * - Native Twig templates (.html.twig) - FS2025 style
 * - Legacy RainTPL templates (.html) - auto-translated to Twig
 * - Plugin view overrides (both legacy 'view' and FS2025 'View' folders)
 * - Compatibility wrappers for header.html/footer.html includes
 */
class Html
{
    private static ?Environment $twig = null;
    
    /** @var float Start time for execution time tracking */
    private static float $startTime = 0;

    /**
     * Render a template with given parameters
     * 
     * @param string $template Template name (without extension for auto-detection)
     * @param array $params Parameters to pass to the template
     * @return string Rendered HTML
     */
    public static function render(string $template, array $params = []): string
    {
        // Track execution time
        if (self::$startTime === 0.0) {
            self::$startTime = microtime(true);
        }
        
        // Inject GLOBALS for compatibility with RainTPL templates that use $GLOBALS
        $params['GLOBALS'] = $GLOBALS;
        
        // Add controller name for FS2025 compatibility
        if (isset($params['fsc']) && is_object($params['fsc'])) {
            $params['controllerName'] = get_class($params['fsc']);
            if (method_exists($params['fsc'], 'getPageData')) {
                $pageData = $params['fsc']->getPageData();
                $params['controllerName'] = $pageData['name'] ?? $params['controllerName'];
            }
        }
        
        return self::renderTwig($template, $params);
    }
    
    /**
     * Get execution time since first render call
     * 
     * @return float Execution time in seconds
     */
    public static function executionTime(): float
    {
        if (self::$startTime === 0.0) {
            return 0.0;
        }
        return round(microtime(true) - self::$startTime, 4);
    }

    /**
     * Initialize and configure Twig environment
     */
    private static function renderTwig(string $template, array $params): string
    {
        if (self::$twig === null) {
            $loader = new class (FS_FOLDER . '/view') extends FilesystemLoader {
                public function getSourceContext(string $name): Source
                {
                    $context = parent::getSourceContext($name);

                    // Only translate legacy .html files (RainTPL syntax)
                    // Native .html.twig files are used as-is
                    if (str_ends_with($name, '.html') && !str_ends_with($name, '.html.twig')) {
                        return new Source(
                            RainToTwig::translate($context->getCode()),
                            $context->getName(),
                            $context->getPath()
                        );
                    }
                    return $context;
                }
            };

            // Add plugin view paths - plugins can override core views
            // Process plugins in reverse order so first plugin in list has highest priority
            $plugins = array_reverse($GLOBALS['plugins'] ?? []);
            foreach ($plugins as $plugin) {
                // Legacy plugins use lowercase 'view' folder
                $pluginPath = FS_FOLDER . '/plugins/' . $plugin . '/view';
                if (is_dir($pluginPath)) {
                    $loader->addPath($pluginPath, $plugin); // Namespaced access: @PluginName/template
                    $loader->prependPath($pluginPath); // Prepend to default namespace for override
                }
                
                // FS2025 plugins use PascalCase 'View' folder
                $pluginViewPath = FS_FOLDER . '/plugins/' . $plugin . '/View';
                if (is_dir($pluginViewPath)) {
                    $loader->addPath($pluginViewPath, $plugin); // Namespaced access: @PluginName/template
                    $loader->prependPath($pluginViewPath); // Prepend to default namespace for override
                }
            }

            // Determine if we're in debug mode
            $isDebug = defined('FS_DEBUG') && FS_DEBUG;
            
            self::$twig = new Environment($loader, [
                'cache' => FS_FOLDER . '/tmp/twig_cache',
                'debug' => $isDebug,
                'auto_reload' => $isDebug, // Only reload templates in debug mode
            ]);

            // Initialize the translation system and register Twig extension
            FSTranslator::initialize(FS_FOLDER);
            FSTranslator::loadAllPluginTranslations();
            self::$twig->addExtension(new TranslationExtension());

            // Register common PHP functions as Twig functions
            $phpFunctions = [
                'constant',
                'in_array',
                'file_exists',
                'class_exists',
                'count',
                'is_array',
                'is_null',
                'strlen',
                'substr',
                'strpos',
                'strtolower',
                'strtoupper',
                'ucfirst',
                'trim',
                'implode',
                'explode',
                'array_key_exists',
                'number_format',
                'date',
                'time',
                'strtotime',
                'json_encode',
                'json_decode',
                'htmlspecialchars',
                'nl2br',
                'round',
                'ceil',
                'floor',
                'abs',
                'min',
                'max',
                'sprintf',
                'preg_match',
                'mt_rand',
                'join',
                'intval',
                'floatval',
                'mb_substr',
                'mb_strlen',
                'mb_strpos',
                'mb_strtolower',
                'mb_strtoupper',
                'base64_encode',
            ];
            foreach ($phpFunctions as $func) {
                self::$twig->addFunction(new \Twig\TwigFunction($func, $func));
            }

            // Register PHP language constructs with wrapper closures
            // (isset, empty, defined are not real functions and can't be passed directly)
            self::$twig->addFunction(new \Twig\TwigFunction('isset', fn($var) => isset($var)));
            self::$twig->addFunction(new \Twig\TwigFunction('empty', fn($var) => empty($var)));
            self::$twig->addFunction(new \Twig\TwigFunction('defined', fn($name) => defined($name)));
            self::$twig->addFunction(new \Twig\TwigFunction('array', fn(...$args) => $args));
            self::$twig->addFunction(new \Twig\TwigFunction('auto_ext', function ($file) {
                if (empty($file)) {
                    return $file;
                }
                return str_ends_with($file, '.html') ? $file : $file . '.html';
            }));

            // Register CSRF protection functions
            self::$twig->addFunction(new \Twig\TwigFunction(
                'csrf_token',
                [CsrfManager::class, 'generateToken']
            ));
            self::$twig->addFunction(new \Twig\TwigFunction(
                'csrf_field',
                [CsrfManager::class, 'field'],
                ['is_safe' => ['html']]
            ));
            self::$twig->addFunction(new \Twig\TwigFunction(
                'csrf_meta',
                [CsrfManager::class, 'metaTag'],
                ['is_safe' => ['html']]
            ));

            // FS2025 formToken function (alias for CSRF)
            // formToken() returns field HTML, formToken(false) returns just the token value
            self::$twig->addFunction(new \Twig\TwigFunction(
                'formToken',
                function ($asField = true) {
                    if ($asField) {
                        return CsrfManager::field();
                    }
                    return CsrfManager::generateToken();
                },
                ['is_safe' => ['html']]
            ));

            // bytes() - Format file sizes in human readable format
            self::$twig->addFunction(new \Twig\TwigFunction(
                'bytes',
                function ($bytes, $precision = 2) {
                    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                    $bytes = max($bytes, 0);
                    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                    $pow = min($pow, count($units) - 1);
                    $bytes /= pow(1024, $pow);
                    return round($bytes, $precision) . ' ' . $units[$pow];
                }
            ));

            // asset() - Generate asset URL with cache busting
            self::$twig->addFunction(new \Twig\TwigFunction(
                'asset',
                function ($path) {
                    $fullPath = FS_FOLDER . '/' . ltrim($path, '/');
                    $version = file_exists($fullPath) ? filemtime($fullPath) : time();
                    return $path . '?v=' . $version;
                }
            ));

            // settings() - Get system settings (stub for FS2025 compatibility)
            self::$twig->addFunction(new \Twig\TwigFunction(
                'settings',
                function ($group = null, $key = null, $default = null) {
                    // Simple mapping to FSFramework config
                    if ($group === 'default' && $key === 'homepage') {
                        return defined('FS_HOMEPAGE') ? FS_HOMEPAGE : 'admin_home';
                    }
                    return $default;
                }
            ));

            // Load plugin functions.php files and register custom functions
            foreach ($GLOBALS['plugins'] ?? [] as $plugin) {
                $functionsFile = FS_FOLDER . '/plugins/' . $plugin . '/functions.php';
                if (file_exists($functionsFile)) {
                    require_once $functionsFile;
                }
            }

            // Register plugin-specific functions (AdminLTE and others)
            $pluginFunctions = [
                'get_gravatar',
                'adminlte_menu_icon',
                'adminlte_page_icon',
                'fs_honest_orig',
                'fs_fake_msg',
                'fs_tipos_id_fiscal',
            ];
            foreach ($pluginFunctions as $func) {
                if (function_exists($func)) {
                    self::$twig->addFunction(new \Twig\TwigFunction($func, $func));
                }
            }
            
            // executionTime() - Get execution time for footer display
            self::$twig->addFunction(new \Twig\TwigFunction(
                'executionTime',
                [self::class, 'executionTime']
            ));
            
            // getIncludeViews() - For FS2025 extension points (stub for now)
            // Returns an empty array; plugins can override this behavior
            self::$twig->addFunction(new \Twig\TwigFunction(
                'getIncludeViews',
                function ($template = null, $position = null) {
                    // Stub implementation - returns empty array
                    // In FS2025, this would return views registered by plugins for extension points
                    return [];
                }
            ));
            
            // debugBarRender - Stub for FS2025 debug bar compatibility
            self::$twig->addGlobal('debugBarRender', new class {
                public function render(): string { return ''; }
                public function renderHead(): string { return ''; }
            });
            
            // assetManager - Stub for FS2025 asset manager compatibility
            self::$twig->addGlobal('assetManager', new class {
                public function get(string $type): array { return []; }
            });
            
            // menuManager - Stub for FS2025 menu manager compatibility
            // Uses fsc.folders() and fsc.pages() instead in FSFramework
            self::$twig->addGlobal('menuManager', new class {
                public function getMenu(): array { return []; }
            });
            
            // log - Stub for FS2025 log compatibility
            self::$twig->addGlobal('log', []);
            
            // template - Current template name
            self::$twig->addGlobal('template', '');
            
            // app - Symfony-like app global with request object
            self::$twig->addGlobal('app', new class {
                public $request;
                
                public function __construct() {
                    $this->request = new class {
                        public function get(string $key, $default = null) {
                            return $_GET[$key] ?? $_POST[$key] ?? $_REQUEST[$key] ?? $default;
                        }
                        public function getMethod(): string {
                            return $_SERVER['REQUEST_METHOD'] ?? 'GET';
                        }
                    };
                }
            });
        }


        // Resolve template name with extension
        $template = self::resolveTemplate($template);
        
        // Update template global for FS2025 compatibility
        self::$twig->addGlobal('template', $template);

        return self::$twig->render($template, $params);
    }
    
    /**
     * Resolve template name to full path with extension
     * 
     * Priority:
     * 1. If template already has extension, use as-is
     * 2. Try .html.twig (native Twig / FS2025)
     * 3. Fall back to .html (legacy RainTPL)
     * 
     * @param string $template Template name
     * @return string Resolved template name with extension
     */
    private static function resolveTemplate(string $template): string
    {
        // Already has an extension
        if (str_contains($template, '.')) {
            return $template;
        }
        
        $loader = self::$twig->getLoader();
        
        // Priority 1: Native Twig template (.html.twig)
        if ($loader->exists($template . '.html.twig')) {
            return $template . '.html.twig';
        }
        
        // Priority 2: Legacy RainTPL template (.html)
        return $template . '.html';
    }
    
    /**
     * Check if a template exists
     * 
     * @param string $template Template name
     * @return bool True if template exists
     */
    public static function templateExists(string $template): bool
    {
        if (self::$twig === null) {
            // Initialize Twig with minimal params
            self::render('', ['fsc' => null]);
        }
        
        $loader = self::$twig->getLoader();
        
        if (str_contains($template, '.')) {
            return $loader->exists($template);
        }
        
        return $loader->exists($template . '.html.twig') || $loader->exists($template . '.html');
    }
    
    /**
     * Get the Twig environment instance (for advanced usage)
     * 
     * @return Environment|null
     */
    public static function getTwig(): ?Environment
    {
        return self::$twig;
    }
}
