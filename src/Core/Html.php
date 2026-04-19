<?php

namespace FSFramework\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
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

    private const VIEW_DIR = '/view';
    private const PLUGINS_DIR = '/plugins/';

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
     * Render a template for AJAX requests (without header/footer).
     * Returns only the main content, suitable for embedding via AJAX.
     * 
     * @param string $template Template name
     * @param array $params Parameters to pass to the template
     * @return string Rendered HTML content only
     */
    public static function renderAjax(string $template, array $params = []): string
    {
        // Mark as AJAX mode to templates
        $params['is_ajax'] = true;
        $params['ajax_mode'] = true;

        // Render the template normally
        $html = self::render($template, $params);

        // If the rendered HTML has no full-page structure, it is already a partial
        if (stripos($html, '<body') === false && stripos($html, '</html>') === false) {
            return trim($html);
        }

        // Try to extract just the main content
        // Look for content-wrapper or similar containers
        $patterns = [
            '/<div[^>]*class="[^"]*content-wrapper[^"]*"[^>]*>(.*)<\/div>\s*<footer/si',
            '/<section[^>]*class="[^"]*content[^"]*"[^>]*>(.*)<\/section>/si',
            '/<main[^>]*>(.*)<\/main>/si',
            '/<div[^>]*class="[^"]*container-fluid[^"]*"[^>]*>(.*)<\/div>\s*<\/div>\s*<footer/si',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return trim($matches[1]);
            }
        }

        // If no pattern matched, try to strip header and footer completely
        $result = preg_replace('/^.*<body[^>]*>/si', '', $html);
        $html = $result ?? $html;
        $result = preg_replace('/<footer.*$/si', '', $html);
        $html = $result ?? $html;
        $result = preg_replace('/<\/body>.*$/si', '', $html);
        $html = $result ?? $html;

        return trim($html);
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
     * Lazily initialize Twig, resolve the template, and render.
     */
    private static function renderTwig(string $template, array $params): string
    {
        if (self::$twig === null) {
            self::$twig = self::createTwigEnvironment();
        }

        $template = self::resolveTemplate($template);
        self::$twig->addGlobal('template', $template);

        return self::$twig->render($template, $params);
    }

    /**
     * Create and fully configure the Twig Environment singleton.
     */
    private static function createTwigEnvironment(): Environment
    {
        $loader = self::buildFilesystemLoader();

        $twig = new Environment($loader, [
            'cache' => FS_FOLDER . '/tmp/twig_cache',
            'debug' => defined('FS_DEBUG') && FS_DEBUG,
            'auto_reload' => true,
        ]);

        self::registerExtensions($twig);
        self::registerFilters($twig);
        self::registerFunctions($twig);
        self::registerGlobals($twig);

        return $twig;
    }

    /**
     * Build the FilesystemLoader with core, theme, and plugin view paths.
     */
    private static function buildFilesystemLoader(): \Twig\Loader\LoaderInterface
    {
        $paths = [];
        if (is_dir(FS_FOLDER . self::VIEW_DIR)) {
            $paths[] = FS_FOLDER . self::VIEW_DIR;
        }
        $loader = new FilesystemLoader($paths);

        $themeViewPath = ThemeManager::getInstance()->getThemeViewPath();
        if ($themeViewPath !== null) {
            $loader->prependPath($themeViewPath);
            $loader->addPath($themeViewPath, 'Theme');
        }

        self::addPluginViewPaths($loader);

        // Re-prepend theme path to ensure highest priority after plugins
        if ($themeViewPath !== null) {
            $loader->prependPath($themeViewPath);
        }

        // Allow plugins to wrap/modify the loader (e.g. legacy_support injects RainTPL translation)
        $dispatcher = \FSFramework\Event\FSEventDispatcher::getInstance();
        $event = new \FSFramework\Event\TwigLoaderEvent($loader);
        $dispatcher->dispatch($event, \FSFramework\Event\TwigLoaderEvent::NAME);

        return $event->getLoader();
    }

    /**
     * Add legacy 'view' and FS2025 'View' paths from each plugin to the loader.
     */
    private static function addPluginViewPaths(FilesystemLoader $loader): void
    {
        $plugins = array_reverse($GLOBALS['plugins'] ?? []);
        foreach ($plugins as $plugin) {
            $pluginBase = FS_FOLDER . self::PLUGINS_DIR . $plugin;

            $legacyPath = $pluginBase . self::VIEW_DIR;
            if (is_dir($legacyPath)) {
                $loader->addPath($legacyPath, $plugin);
                $loader->prependPath($legacyPath);
            }

            $modernPath = $pluginBase . '/View';
            if (is_dir($modernPath)) {
                $loader->addPath($modernPath, $plugin);
                $loader->prependPath($modernPath);
            }
        }
    }

    /**
     * Register Twig extensions (translation, plugin-provided via TwigInitEvent).
     */
    private static function registerExtensions(Environment $twig): void
    {
        FSTranslator::initialize(FS_FOLDER);
        FSTranslator::loadAllPluginTranslations();
        $twig->addExtension(new TranslationExtension());

        $dispatcher = \FSFramework\Event\FSEventDispatcher::getInstance();
        $twigInitEvent = new \FSFramework\Event\TwigInitEvent($twig);
        $dispatcher->dispatch($twigInitEvent, \FSFramework\Event\TwigInitEvent::NAME);
    }

    /**
     * Register custom Twig filters.
     */
    private static function registerFilters(Environment $twig): void
    {
        $twig->addFilter(new \Twig\TwigFilter('base64_encode', 'base64_encode'));
    }

    /**
     * Register all custom Twig functions.
     */
    private static function registerFunctions(Environment $twig): void
    {
        self::registerCsrfFunctions($twig);
        self::registerUtilityFunctions($twig);
        self::registerCorePHPFunctions($twig);
        self::loadThemeFunctions($twig);
        self::loadPluginFunctions($twig);
    }

    private static function registerCsrfFunctions(Environment $twig): void
    {
        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_token',
            [CsrfManager::class, 'generateToken']
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_field',
            [CsrfManager::class, 'field'],
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_meta',
            [CsrfManager::class, 'metaTag'],
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'formToken',
            function ($asField = true) {
                return $asField ? CsrfManager::field() : CsrfManager::generateToken();
            },
            ['is_safe' => ['html']]
        ));
    }

    private static function registerUtilityFunctions(Environment $twig): void
    {
        $twig->addFunction(new \Twig\TwigFunction(
            'theme_manager',
            fn() => ThemeManager::getInstance()
        ));

        $twig->addFunction(new \Twig\TwigFunction(
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

        $twig->addFunction(new \Twig\TwigFunction(
            'asset',
            function ($path) {
                $fullPath = FS_FOLDER . '/' . ltrim($path, '/');
                $version = file_exists($fullPath) ? filemtime($fullPath) : time();
                return $path . '?v=' . $version;
            }
        ));

        $twig->addFunction(new \Twig\TwigFunction(
            'settings',
            function ($group = null, $key = null, $default = null) {
                if ($group === 'default' && $key === 'homepage') {
                    return defined('FS_HOMEPAGE') ? FS_HOMEPAGE : 'admin_home';
                }
                return $default;
            }
        ));

        $twig->addFunction(new \Twig\TwigFunction(
            'resolve_template',
            function ($template) {
                if (empty($template) || strpos($template, '.') !== false) {
                    return $template;
                }
                return self::resolveTemplate($template);
            }
        ));

        $twig->addFunction(new \Twig\TwigFunction(
            'executionTime',
            [self::class, 'executionTime']
        ));

        $twig->addFunction(new \Twig\TwigFunction(
            'getIncludeViews',
            function ($template = null, $position = null) {
                return [];
            }
        ));
    }

    private static function registerCorePHPFunctions(Environment $twig): void
    {
        $coreFunctions = [
            'file_exists', 'constant', 'count', 'is_array', 'in_array',
            'nl2br', 'json_encode', 'json_decode', 'time', 'date',
            'number_format', 'sprintf', 'str_replace', 'ceil', 'floor',
            'round', 'class_exists', 'mt_rand', 'rand', 'substr',
            'mb_substr', 'urlencode',
        ];

        foreach ($coreFunctions as $func) {
            self::tryAddFunction($twig, $func);
        }
    }

    /**
     * Load and register functions from the active theme's functions.php.
     */
    private static function loadThemeFunctions(Environment $twig): void
    {
        $themeManager = ThemeManager::getInstance();
        $path = FS_FOLDER . '/themes/' . $themeManager->getActiveTheme() . '/functions.php';
        if (is_file($path)) {
            require_once $path;
        }

        foreach (['get_gravatar', 'adminlte_menu_icon', 'adminlte_page_icon'] as $func) {
            self::tryAddFunction($twig, $func);
        }
    }

    /**
     * Load plugin functions.php files and register known plugin functions.
     */
    private static function loadPluginFunctions(Environment $twig): void
    {
        foreach ($GLOBALS['plugins'] ?? [] as $plugin) {
            $path = FS_FOLDER . self::PLUGINS_DIR . $plugin . '/functions.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $pluginFunctions = [
            'get_gravatar', 'adminlte_menu_icon', 'adminlte_page_icon',
            'plantilla_email', 'fs_tipos_id_fiscal',
            'fs_honest_orig', 'fs_fake_msg',
        ];
        foreach ($pluginFunctions as $func) {
            self::tryAddFunction($twig, $func);
        }
    }

    /**
     * Try to register a PHP function in Twig, silently ignoring duplicates.
     */
    private static function tryAddFunction(Environment $twig, string $name): void
    {
        if (!function_exists($name)) {
            return;
        }
        try {
            $twig->addFunction(new \Twig\TwigFunction($name, $name));
        } catch (\LogicException) {
        }
    }

    /**
     * Register Twig globals (theme path, FS2025 compatibility stubs, etc.).
     */
    private static function registerGlobals(Environment $twig): void
    {
        $twig->addGlobal('theme_path', ThemeManager::getInstance()->getThemeAssetsPath());

        if (isset($GLOBALS['config2'])) {
            $twig->addGlobal('config2', $GLOBALS['config2']);
        }

        $twig->addGlobal('debugBarRender', new class {
            public function render(): string { return ''; }
            public function renderHead(): string { return ''; }
        });

        $twig->addGlobal('assetManager', new class {
            public function get(string $type): array { return []; }
        });

        $twig->addGlobal('menuManager', new class {
            public function getMenu(): array { return []; }
        });

        $twig->addGlobal('log', []);
        $twig->addGlobal('template', '');

        $twig->addGlobal('app', new class {
            public $request;
            public function __construct()
            {
                $this->request = new class {
                    public function get(string $key, $default = null)
                    {
                        return $_GET[$key] ?? $_POST[$key] ?? $_REQUEST[$key] ?? $default;
                    }
                    public function getMethod(): string
                    {
                        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
                    }
                };
            }
        });
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

        $twigTemplate = $template . '.html.twig';
        if (self::$twig !== null && self::$twig->getLoader()->exists($twigTemplate)) {
            return $twigTemplate;
        }

        if (self::isLegacySupportEnabled()) {
            $htmlTemplate = $template . '.html';
            if (self::$twig !== null && self::$twig->getLoader()->exists($htmlTemplate)) {
                return $htmlTemplate;
            }
        }

        return $twigTemplate;
    }

    private static function isLegacySupportEnabled(): bool
    {
        return in_array('legacy_support', $GLOBALS['plugins'] ?? [], true);
    }

}
