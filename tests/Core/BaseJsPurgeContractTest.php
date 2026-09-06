<?php

declare(strict_types=1);

/**
 * Contract tests for the phase 6 base.js purge: dead code moved to the
 * legacy_support plugin, live surface kept.
 *
 * view/js/base.js must keep only the live code (number_format, parse_number,
 * fsIsSafeClickableHref, the clickableRow delegation and the modal autofocus,
 * which stays because core templates still emit class="modal"). The moved
 * legacy API (init_modal_iframe, ajax_form, fs_confirm, fs_alert, fs_prompt,
 * show_precio, the [data-confirm] binding and the tooltip/popover auto-init)
 * must be gone from core and now lives in
 * plugins/legacy_support/view/js/legacy-base.js.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class BaseJsPurgeContractTest extends TestCase
{
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    private function baseJsSource(): string
    {
        $source = file_get_contents(FS_FOLDER . '/view/js/base.js');

        $this->assertNotFalse($source);

        return (string) $source;
    }

    private function renderHeader(): string
    {
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
            fn(): string => 'nonce="test-nonce"',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('file_exists', fn(string $path): bool => false));
        $twig->addFunction(new TwigFunction('get_gravatar', fn(string $email): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_menu_icon', fn(string $folder): string => ''));
        $twig->addFunction(new TwigFunction('adminlte_page_icon', fn($page): string => ''));
        $twig->addFilter(new TwigFilter('trans', fn(string $key): string => $key));

        return $twig->render('header.html.twig', [
            'fsc' => new StubBasePurgeHeaderController(),
        ]);
    }

    // =====================================================================
    // base.js keeps the live surface
    // =====================================================================

    #[Test]
    public function baseJsKeepsTheLiveHelpers(): void
    {
        $source = $this->baseJsSource();

        $this->assertStringContainsString('function number_format(', $source);
        $this->assertStringContainsString('function parse_number(', $source);
        $this->assertStringContainsString('function fsIsSafeClickableHref(', $source);
    }

    #[Test]
    public function baseJsKeepsTheClickableRowDelegation(): void
    {
        $source = $this->baseJsSource();

        $this->assertStringContainsString('tr.clickableRow[href]', $source);
        $this->assertStringContainsString('.closest(', $source);
    }

    #[Test]
    public function fsIsSafeClickableHrefKeepsTheSecurityChecks(): void
    {
        $source = $this->baseJsSource();

        // Dangerous protocol blocklist.
        $this->assertStringContainsString('/^(javascript|data|vbscript):/i', $source);
        // Same-origin enforcement.
        $this->assertStringContainsString('url.origin !== window.location.origin', $source);
    }

    #[Test]
    public function baseJsKeepsTheModalAutofocus(): void
    {
        // Core templates (feedback, admin_agentes, admin_users, admin_home,
        // admin_user, header) still emit class="modal", so the autofocus
        // binding stays live in core instead of moving to legacy_support.
        $source = $this->baseJsSource();

        $this->assertStringContainsString("$('.modal').on('shown.bs.modal'", $source);
        $this->assertStringContainsString('input:visible:first', $source);
    }

    // =====================================================================
    // base.js drops the legacy API moved to legacy_support
    // =====================================================================

    #[Test]
    public function baseJsDropsTheLegacyApiMovedToLegacySupport(): void
    {
        $source = $this->baseJsSource();

        foreach ([
            'init_modal_iframe',
            'ajax_form',
            'fs_confirm',
            'fs_alert',
            'fs_prompt',
            'show_precio',
        ] as $symbol) {
            $this->assertStringNotContainsString($symbol, $source, $symbol . ' moved to legacy_support');
        }
    }

    #[Test]
    public function baseJsDropsTheDeadConfirmAndTooltipInit(): void
    {
        $source = $this->baseJsSource();

        $this->assertStringNotContainsString('[data-confirm]', $source);
        $this->assertStringNotContainsString('data-toggle="tooltip"', $source);
        $this->assertStringNotContainsString('data-toggle="popover"', $source);
    }

    // =====================================================================
    // Header contract
    // =====================================================================

    #[Test]
    public function headerStillLoadsBaseJsExactlyOnce(): void
    {
        $output = $this->renderHeader();

        $this->assertSame(
            1,
            substr_count($output, 'src="view/js/base.js'),
            'header.html.twig must load view/js/base.js exactly once'
        );
    }
}

/**
 * Minimal fsc stub covering every property/method header.html.twig touches.
 * Same recipe as FsDialogsShimContractTest with a unique class name so all
 * header-rendering contract tests can live in the same PHPUnit process.
 */
class StubBasePurgeHeaderController
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
