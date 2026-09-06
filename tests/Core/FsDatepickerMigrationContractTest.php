<?php

declare(strict_types=1);

/**
 * Contract tests for the jQuery migration phase 4 (datepicker -> native date
 * inputs) plus the generic head_extra_js/head_extra_css plugin asset hook.
 *
 * Core side: every date emitter (fs_edit_form, fs_list_filter_date, the agente
 * templates) emits native <input type="date"> with values converted from the
 * model d-m-Y format via the strict date_iso filter / date_to_iso helpers;
 * base.js loses the datepicker auto-init block including the type="date"
 * downgrade; the global header and install.php stop loading jQuery UI,
 * bootstrap-datepicker and its CSS. The legacy asset files stay on disk for
 * rollback safety (covered here) and for old plugins (covered by the
 * legacy_support plugin tests).
 */

namespace Tests\Core;

use FSFramework\Core\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class FsDatepickerMigrationContractTest extends TestCase
{
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    // =====================================================================
    // date_iso filter semantics (Html::dateIsoValue)
    // =====================================================================

    #[Test]
    #[DataProvider('provideDateIsoCases')]
    public function dateIsoConvertsStrictlyAndFallsBackToOriginal(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Html::dateIsoValue($input));
    }

    public static function provideDateIsoCases(): array
    {
        return [
            'full d-m-Y date'        => ['31-01-2026', '2026-01-31'],
            'single digit d-m-Y'     => ['5-1-2026', '2026-01-05'],
            'leap year converted'    => ['29-02-2024', '2024-02-29'],
            'non-leap 29-02 kept'    => ['29-02-2023', '29-02-2023'],
            'month out of range'     => ['15-13-2026', '15-13-2026'],
            'already ISO unchanged'  => ['2026-01-31', '2026-01-31'],
            'slash date unchanged'   => ['31/01/2026', '31/01/2026'],
            'invalid day unchanged'  => ['31-02-2026', '31-02-2026'],
            'empty string'           => ['', ''],
            'null'                   => [null, null],
        ];
    }

    #[Test]
    public function dateIsoPassesNonTextualValuesThrough(): void
    {
        $this->assertSame(42, Html::dateIsoValue(42));
        $this->assertSame(0, Html::dateIsoValue(0));
        $this->assertSame(3.14, Html::dateIsoValue(3.14));
        $this->assertSame(true, Html::dateIsoValue(true));
        $this->assertSame(['31-01-2026'], Html::dateIsoValue(['31-01-2026']));
    }

    #[Test]
    public function dateIsoFilterIsRegisteredInProductionTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            'converted' => "{{ '31-01-2026'|date_iso }}",
            'fallback'  => "{{ 'nonsense-31/02'|date_iso }}",
        ]));

        // Invoke the production registration so the filter name and callback
        // wiring are contract-tested, not a parallel stub.
        $method = new \ReflectionMethod(Html::class, 'registerFilters');
        $method->setAccessible(true);
        $method->invoke(null, $twig);

        $this->assertSame('2026-01-31', $twig->render('converted'));
        $this->assertSame('nonsense-31/02', $twig->render('fallback'));
    }

    // =====================================================================
    // Header: legacy-free assets + generic head_extra hooks
    // =====================================================================

    private function makeTwig(): Environment
    {
        // Read-only FilesystemLoader rooted at the theme view directory,
        // mirroring how Html::render() resolves theme templates.
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));

        // Stubs mirroring production registration; every function/filter the
        // global header references must exist for the template to compile.
        $twig->addFunction(new TwigFunction(
            'csrf_meta',
            fn(): string => '<meta name="csrf-token" content="testcsrftoken">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction(
            'csp_nonce_attr',
            fn(): string => self::STUB_NONCE,
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('file_exists', fn(string $path): bool => false));
        $twig->addFunction(new TwigFunction('get_gravatar', fn(string $email): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_menu_icon', fn(string $folder): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_page_icon', fn($page): string => ''));
        $twig->addFilter(new TwigFilter('trans', fn(string $key): string => $key));

        return $twig;
    }

    private function renderHeader(): string
    {
        return $this->makeTwig()->render('header.html.twig', [
            'fsc' => new StubDatepickerHeaderController(),
        ]);
    }

    #[Test]
    public function headerNoLongerLoadsLegacyDatepickerAssets(): void
    {
        $output = $this->renderHeader();

        $this->assertStringNotContainsString('jquery-ui.min.js', $output);
        $this->assertStringNotContainsString('bootstrap-datepicker.js', $output);
        $this->assertStringNotContainsString('datepicker.css', $output);
    }

    #[Test]
    public function headerStillLoadsTheCoreScriptChain(): void
    {
        $output = $this->renderHeader();

        $this->assertSame(1, substr_count($output, 'src="view/js/fs-dialogs.js"'));
        $this->assertSame(1, substr_count($output, 'src="view/js/base.js'));
        $this->assertStringContainsString('src="view/js/jquery.min.js"', $output);
        $this->assertStringContainsString('src="view/js/bootstrap.min.js"', $output);
    }

    #[Test]
    public function headerTemplateDeclaresTheGenericHeadExtraLoops(): void
    {
        $source = file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/header.html.twig');

        $this->assertNotFalse($source);
        $this->assertStringContainsString("{% for css_url in head_extra_css|default([]) %}", (string) $source);
        $this->assertStringContainsString("{% for js_url in head_extra_js|default([]) %}", (string) $source);
    }

    #[Test]
    public function emptyHeadExtraGlobalsEmitNoExtraTags(): void
    {
        $output = $this->renderHeader();

        $this->assertStringNotContainsString('plugins/legacy_support/', $output);
        // The loops must not render dangling empty script/link tags.
        $this->assertStringNotContainsString('src=""', $output);
        $this->assertStringNotContainsString('href=""', $output);
    }

    #[Test]
    public function headExtraGlobalsRenderNonceSignedTagsBeforeBaseJs(): void
    {
        $twig = $this->makeTwig();
        $twig->addGlobal('head_extra_js', ['view/js/jquery-ui.min.js']);
        $twig->addGlobal('head_extra_css', ['view/css/datepicker.css']);

        $output = $twig->render('header.html.twig', [
            'fsc' => new StubDatepickerHeaderController(),
        ]);

        $this->assertMatchesRegularExpression(
            '/<script nonce="test-nonce" type="text\/javascript" src="view\/js\/jquery-ui\.min\.js\?v=20260906"><\/script>/',
            $output
        );
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="view/css/datepicker.css" />',
            $output
        );

        $jsPos = strpos($output, 'src="view/js/jquery-ui.min.js?v=20260906"');
        $baseJsPos = strpos($output, 'src="view/js/base.js');
        $this->assertNotFalse($jsPos);
        $this->assertNotFalse($baseJsPos);
        $this->assertLessThan($baseJsPos, $jsPos, 'head_extra_js tags must load before base.js');
    }

    // =====================================================================
    // base.js: auto-init block removed, no type="date" downgrade
    // =====================================================================

    #[Test]
    public function baseJsDropsTheDatepickerAutoInitAndDowngrade(): void
    {
        $source = file_get_contents(FS_FOLDER . '/view/js/base.js');

        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringNotContainsString("attr('type', 'text'", $source);
        $this->assertStringNotContainsString('.datepicker({', $source);
        $this->assertStringNotContainsString('input[type="date"]', $source);
        // The rest of the ready block stays intact; the modal-iframe init moved
        // to legacy_support (phase 6: dead in core, kept for old plugins).
        $this->assertStringContainsString('$(document).ready(function()', $source);
        $this->assertStringNotContainsString('init_modal_iframe', $source);
    }

    // =====================================================================
    // Core date emitters: native inputs + strict conversion
    // =====================================================================

    #[Test]
    public function fsEditFormEmitsNativeDateInputsWithoutDatepickerClass(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/base/fs_edit_form.php');

        $this->assertStringContainsString('type="date"', $source);
        $this->assertStringNotContainsString('datepicker', $source);
        $this->assertStringContainsString('date_to_iso', $source);
    }

    #[Test]
    public function fsListFilterDateEmitsNativeDateInputAndKeepsAutoSubmit(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/base/fs_list_filter_date.php');

        $this->assertStringContainsString('type="date"', $source);
        $this->assertStringNotContainsString('datepicker', $source);
        $this->assertStringContainsString('date_to_iso', $source);
        $this->assertStringContainsString('onchange="this.form.submit()"', $source);
    }

    #[Test]
    public function agenteTemplatesUseNativeDateInputsWithDateIso(): void
    {
        foreach (['/admin_agente.html.twig', '/admin_agentes.html.twig'] as $template) {
            $source = (string) file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . $template);

            $this->assertStringNotContainsString('datepicker', $source, $template . ' must not use the datepicker class');
            $this->assertStringContainsString('type="date"', $source);
            $this->assertStringContainsString('|date_iso', $source, $template . ' must convert date values with date_iso');
        }
    }

    // =====================================================================
    // install.php: unused legacy loaders removed
    // =====================================================================

    #[Test]
    public function installPhpNoLongerLoadsDatepickerAssets(): void
    {
        $source = file_get_contents(FS_FOLDER . '/install.php');

        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringNotContainsString('bootstrap-datepicker.js', $source);
        $this->assertStringNotContainsString('datepicker.css', $source);
    }

    // =====================================================================
    // Retro invariants: legacy asset files stay on disk
    // =====================================================================

    #[Test]
    public function legacyAssetFilesRemainOnDiskForRollback(): void
    {
        foreach ([
            '/view/js/jquery-ui.min.js',
            '/view/js/bootstrap-datepicker.js',
            '/view/css/datepicker.css',
            '/view/js/jquery.autocomplete.min.js',
            '/view/js/jquery.ui.shake.js',
            '/view/js/bootbox.min.js',
        ] as $asset) {
            $this->assertFileExists(FS_FOLDER . $asset);
        }
    }
}

/**
 * Minimal fsc stub covering every property/method header.html.twig touches.
 * Same recipe as FsDialogsShimContractTest with a unique class name so both
 * files can live in the same PHPUnit process.
 */
class StubDatepickerHeaderController
{
    public array $extensions = [];
    public object $page;
    public object $user;

    public function __construct()
    {
        $this->page = new class {
            public string $title = 'Test';
            public string $folder = 'admin';
            public function url(): string
            {
                return '#';
            }
        };
        $this->user = new class {
            public string $nick = 'admin';
            public string $email = 'admin@example.com';
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

    public function today(): string
    {
        return '20260906';
    }

    public function check_for_updates(): bool
    {
        return false;
    }

    public function get_last_changes(): array
    {
        return [];
    }

    public function folders(): array
    {
        return [];
    }

    public function pages(string $folder): array
    {
        return [];
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

    public function logoutUrl(): string
    {
        return '#';
    }
}
