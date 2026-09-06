<?php

declare(strict_types=1);

/**
 * Contract tests for the jQuery migration phases 2-3 (shake + sortable).
 *
 * Phase 2: the login and password reset pages stop loading
 * jquery.ui.shake.js and shake via a CSS @keyframes animation (fs-shake)
 * instead. The keyframes live in view/css/custom.css, which both standalone
 * pages and the global header already load; the vendored plugin file stays
 * on disk for rollback safety.
 *
 * Phase 3: jQuery UI sortable is replaced by the vendored SortableJS build.
 * package.json pins the version, build.sh copies it into view/js/, and
 * admin_orden_menu.html.twig loads it (nonce'd) while keeping the
 * orden[<folder>][] hidden-input POST contract byte-identical.
 *
 * themes/AdminLTE/js/pages/dashboard.js is dead code: a repo-wide search
 * found no template, controller or plugin loading it, so its jQuery UI
 * sortable usage is consciously left untouched. The guard test below fails
 * if any core theme template ever starts loading it, forcing the migration
 * decision to be revisited.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class FsSortableMigrationContractTest extends TestCase
{
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';
    private const SORTABLEJS_VERSION = '1.15.6';

    private function makeTwig(): Environment
    {
        // Read-only FilesystemLoader rooted at the theme view directory,
        // mirroring how Html::render() resolves theme templates.
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));

        // Stubs mirroring production registration (src/Core/Html.php); every
        // function/filter referenced by header, footer and admin_orden_menu
        // must exist so the page renders hermetically without a database.
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            fn(): string => '<input type="hidden" name="csrf_token" value="testcsrftoken"/>',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('csrf_meta', fn(): string => ''));
        $twig->addFunction(new TwigFunction(
            'csp_nonce_attr',
            fn(): string => self::STUB_NONCE,
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('file_exists', fn(string $path): bool => false));
        $twig->addFunction(new TwigFunction('get_gravatar', fn(string $email): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_menu_icon', fn(string $folder): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_page_icon', fn($page): string => ''));
        // Html.php registers a set of PHP functions verbatim, including defined().
        $twig->addFunction(new TwigFunction('defined', fn(string $name): bool => defined($name)));
        $twig->addFilter(new TwigFilter('trans', fn(string $key): string => $key));

        return $twig;
    }

    private function renderOrdenMenu(): string
    {
        return $this->makeTwig()->render('admin_orden_menu.html.twig', [
            'fsc' => new StubOrdenMenuController(),
        ]);
    }

    private function readSource(string $relativePath): string
    {
        $content = file_get_contents(FS_FOLDER . $relativePath);

        $this->assertNotFalse($content, $relativePath . ' must be readable');

        return (string) $content;
    }

    // =====================================================================
    // Phase 3a: SortableJS vendoring contract
    // =====================================================================

    #[Test]
    public function packageJsonPinsTheSortableJsVersion(): void
    {
        $package = json_decode($this->readSource('/package.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            self::SORTABLEJS_VERSION,
            $package['dependencies']['sortablejs'] ?? null,
            'package.json must pin the exact sortablejs version (no caret)'
        );
    }

    #[Test]
    public function buildScriptCopiesSortableJsIntoViewJs(): void
    {
        $build = $this->readSource('/build.sh');

        $this->assertStringContainsString(
            'cp node_modules/sortablejs/Sortable.min.js view/js/',
            $build
        );
    }

    #[Test]
    public function sortableJsAssetIsVendoredAndMinified(): void
    {
        $path = FS_FOLDER . '/view/js/Sortable.min.js';
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertGreaterThan(10000, strlen($source), 'vendored Sortable.min.js looks truncated');
        $this->assertStringContainsString(
            'Sortable ' . self::SORTABLEJS_VERSION,
            $source,
            'version banner must match the pinned version'
        );
        $this->assertLessThanOrEqual(10, substr_count($source, "\n"), 'asset must be the minified build');
    }

    // =====================================================================
    // Phase 3b: admin_orden_menu pilot contract
    // =====================================================================

    #[Test]
    public function ordenMenuLoadsVendoredSortableJsExactlyOnce(): void
    {
        $output = $this->renderOrdenMenu();

        $this->assertSame(
            1,
            substr_count($output, '<script src="view/js/Sortable.min.js"'),
            'admin_orden_menu must load view/js/Sortable.min.js exactly once'
        );
    }

    #[Test]
    public function sortableScriptTagCarriesTheCspNonce(): void
    {
        $output = $this->renderOrdenMenu();

        $this->assertMatchesRegularExpression(
            '/<script src="view\/js\/Sortable\.min\.js" nonce="test-nonce"><\/script>/',
            $output
        );
    }

    #[Test]
    public function sortableAssetLoadsBeforeTheInlineInit(): void
    {
        $output = $this->renderOrdenMenu();

        $assetPos = strpos($output, '<script src="view/js/Sortable.min.js"');
        $initPos = strpos($output, 'Sortable.create(');

        $this->assertNotFalse($assetPos);
        $this->assertNotFalse($initPos);
        $this->assertLessThan($initPos, $assetPos, 'Sortable.min.js must load before the inline init');
    }

    #[Test]
    public function ordenMenuNoLongerUsesJqueryUiSortable(): void
    {
        $output = $this->renderOrdenMenu();

        $this->assertStringNotContainsString('$(".sortable")', $output);
        $this->assertStringNotContainsString('disableSelection', $output);
    }

    #[Test]
    public function ordenMenuCreatesSortableInstancesWithAnimation(): void
    {
        $output = $this->renderOrdenMenu();

        $this->assertMatchesRegularExpression(
            '/Sortable\.create\(el,\s*\{\s*animation:\s*150\s*\}\)/',
            $output
        );
    }

    #[Test]
    public function ordenMenuKeepsTheHiddenOrderInputsContract(): void
    {
        $output = $this->renderOrdenMenu();

        // The hidden inputs must stay inside the <li> items so DOM reordering
        // alone drives the plain POST submit: one orden[<folder>][] per page.
        $this->assertSame(
            2,
            substr_count($output, 'name="orden[Test][]"'),
            'the two fake pages of the stub folder must each emit an orden[Test][] input'
        );
        $this->assertStringContainsString('value="test_page_one"', $output);
        $this->assertStringContainsString('value="test_page_two"', $output);
    }

    // =====================================================================
    // Phase 2: shake animation contract
    // =====================================================================

    #[Test]
    public function loginAndPasswordResetDropTheShakePlugin(): void
    {
        foreach (['/login/default.html.twig', '/password_reset.html.twig'] as $template) {
            $source = $this->readSource(self::THEME_VIEW_DIR . $template);

            $this->assertStringNotContainsString(
                'jquery.ui.shake.js',
                $source,
                $template . ' must not load jquery.ui.shake.js'
            );
            $this->assertStringNotContainsString(
                '.shake(',
                $source,
                $template . ' must not call the jQuery shake plugin'
            );
        }
    }

    #[Test]
    public function loginAndPasswordResetShakeViaCssClass(): void
    {
        foreach (['/login/default.html.twig', '/password_reset.html.twig'] as $template) {
            $source = $this->readSource(self::THEME_VIEW_DIR . $template);

            $this->assertStringContainsString(
                "classList.add('fs-shake')",
                $source,
                $template . ' must trigger the CSS shake animation'
            );
        }
    }

    #[Test]
    public function shakeKeyframesLiveInSharedCustomCss(): void
    {
        // Both standalone pages and the global header already load
        // view/css/custom.css, so the keyframes are declared there once.
        $css = $this->readSource('/view/css/custom.css');

        $this->assertStringContainsString('@keyframes fs-shake', $css);
        $this->assertStringContainsString('.fs-shake', $css);
    }

    #[Test]
    public function shakePluginFileRemainsOnDiskForRollback(): void
    {
        $this->assertFileExists(FS_FOLDER . '/view/js/jquery.ui.shake.js');
    }

    // =====================================================================
    // Phase 3c: dashboard.js decision guard
    // =====================================================================

    #[Test]
    public function noCoreThemeTemplateLoadsDashboardJs(): void
    {
        $templates = new \CallbackFilterIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(FS_FOLDER . self::THEME_VIEW_DIR)
            ),
            fn(\SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'twig'
        );

        $checked = 0;
        foreach ($templates as $file) {
            $checked++;
            $this->assertStringNotContainsString(
                'dashboard.js',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' loads dashboard.js: migrate its jQuery UI sortable calls to SortableJS'
            );
        }

        $this->assertGreaterThan(30, $checked, 'expected to scan the full AdminLTE template tree');
    }
}

/**
 * Minimal fsc stub covering every property/method the header, footer and
 * admin_orden_menu templates touch. One folder with two fake pages makes the
 * orden[<folder>][] contract observable while keeping every loop hermetic.
 */
class StubOrdenMenuController
{
    public array $extensions = [];
    public object $page;
    public object $user;

    public function __construct()
    {
        $this->page = new class {
            public string $title = 'Ordenar menu';
            public string $folder = 'Test';

            public function url(): string
            {
                return '#';
            }
        };
        $this->user = new class {
            public string $nick = 'admin';
            public string $email = 'admin@example.com';
            public string $last_login = '';

            public function get_menu(): array
            {
                return [];
            }

            public function get_agente_fullname(): string
            {
                return '';
            }

            public function url(): string
            {
                return '#';
            }
        };
    }

    public function url(): string
    {
        return '#';
    }

    public function folders(): array
    {
        return ['Test'];
    }

    public function pages(string $folder): array
    {
        return [
            $this->fakePage('test_page_one', 'Página uno'),
            $this->fakePage('test_page_two', 'Página dos'),
        ];
    }

    private function fakePage(string $name, string $title): object
    {
        return new class($name, $title) {
            public function __construct(
                public string $name,
                public string $title
            ) {
            }

            public function url(): string
            {
                return '#';
            }

            public function showing(): bool
            {
                return false;
            }
        };
    }

    public function get_errors(): array
    {
        return [];
    }

    public function get_messages(): array
    {
        return [];
    }

    public function get_advices(): array
    {
        return [];
    }

    public function get_last_changes(): array
    {
        return [];
    }

    public function get_db_history(): array
    {
        return [];
    }

    public function check_for_updates(): bool
    {
        return false;
    }

    public function today(): string
    {
        return '20260906';
    }

    public function logoutUrl(): string
    {
        return '#';
    }

    public function fs_version(): string
    {
        return 'test';
    }

    public function selects(): int
    {
        return 0;
    }

    public function transactions(): int
    {
        return 0;
    }

    public function duration(): string
    {
        return '0s';
    }
}
