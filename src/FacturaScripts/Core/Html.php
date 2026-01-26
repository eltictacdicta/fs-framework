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
 */
class Html
{
    private static ?Environment $twig = null;

    public static function render(string $template, array $params = []): string
    {
        // Inject GLOBALS for compatibility with RainTPL templates that use $GLOBALS
        $params['GLOBALS'] = $GLOBALS;
        return self::renderTwig($template, $params);
    }


    private static function renderTwig(string $template, array $params): string
    {
        if (self::$twig === null) {
            $loader = new class (FS_FOLDER . '/view') extends FilesystemLoader {
                public function getSourceContext(string $name): Source
                {
                    $context = parent::getSourceContext($name);

                    if (str_ends_with($name, '.html')) {
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
                $pluginPath = FS_FOLDER . '/plugins/' . $plugin . '/view';
                if (is_dir($pluginPath)) {
                    $loader->addPath($pluginPath, $plugin); // Namespaced access: @PluginName/template
                    $loader->prependPath($pluginPath); // Prepend to default namespace for override
                }
            }

            self::$twig = new Environment($loader, [
                'cache' => FS_FOLDER . '/tmp/twig_cache',
                'debug' => true,
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
        }


        if (!str_contains($template, '.')) {
            $template .= '.html';
        }

        return self::$twig->render($template, $params);
    }


}
