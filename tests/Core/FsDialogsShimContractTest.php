<?php

declare(strict_types=1);

/**
 * Contract tests for the bootbox-compatible native dialog shim (fs-dialogs.js).
 *
 * fs-dialogs.js replaces bootbox.min.js (jQuery + Bootstrap JS based) as the
 * global dialog provider: header.html.twig must load it exactly once, with a
 * CSP nonce, before base.js. Phase 0 also removes the dead
 * jquery.autocomplete.min.js loader from the global header and from
 * install.php (both legacy files stay on disk for rollback safety).
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class FsDialogsShimContractTest extends TestCase
{
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

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
            'fsc' => new StubHeaderController(),
        ]);
    }

    // =====================================================================
    // Script loading contract (header.html.twig)
    // =====================================================================

    #[Test]
    public function headerLoadsFsDialogsScriptExactlyOnce(): void
    {
        $output = $this->renderHeader();

        $this->assertSame(
            1,
            substr_count($output, 'src="view/js/fs-dialogs.js"'),
            'header.html.twig must load view/js/fs-dialogs.js exactly once'
        );
    }

    #[Test]
    public function fsDialogsScriptTagCarriesTheCspNonce(): void
    {
        $output = $this->renderHeader();

        $this->assertMatchesRegularExpression(
            '/<script nonce="test-nonce" type="text\/javascript" src="view\/js\/fs-dialogs\.js"><\/script>/',
            $output
        );
    }

    #[Test]
    public function fsDialogsScriptLoadsBeforeBaseJs(): void
    {
        $output = $this->renderHeader();

        $fsDialogsPos = strpos($output, 'src="view/js/fs-dialogs.js"');
        $baseJsPos = strpos($output, 'src="view/js/base.js');

        $this->assertNotFalse($fsDialogsPos);
        $this->assertNotFalse($baseJsPos);
        $this->assertLessThan($baseJsPos, $fsDialogsPos, 'fs-dialogs.js must load before base.js');
    }

    #[Test]
    public function headerNoLongerReferencesBootboxOrDeadAutocomplete(): void
    {
        $output = $this->renderHeader();

        $this->assertStringNotContainsString('bootbox.min.js', $output);
        $this->assertStringNotContainsString('jquery.autocomplete.min.js', $output);
    }

    // =====================================================================
    // Phase 0: dead autocomplete loader removed from install.php
    // =====================================================================

    #[Test]
    public function installPhpNoLongerLoadsJqueryAutocomplete(): void
    {
        $source = file_get_contents(FS_FOLDER . '/install.php');

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('jquery.autocomplete.min.js', (string) $source);
    }

    // =====================================================================
    // Phase 1: shim file API surface and CSP safety
    // =====================================================================

    #[Test]
    public function shimFileExposesTheRequiredApiSurface(): void
    {
        $path = FS_FOLDER . '/view/js/fs-dialogs.js';
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        foreach (['alert', 'confirm', 'prompt', 'hideAll'] as $method) {
            $this->assertMatchesRegularExpression(
                '/\b' . $method . ':\s*function/',
                $source,
                "fs-dialogs.js must expose bootbox.{$method}"
            );
        }
    }

    #[Test]
    public function shimIsCspSafe(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/view/js/fs-dialogs.js');

        $this->assertStringNotContainsString('eval(', $source);
        $this->assertStringNotContainsString('new Function(', $source);
    }

    #[Test]
    public function legacyAssetFilesRemainOnDiskForRollback(): void
    {
        $this->assertFileExists(FS_FOLDER . '/view/js/bootbox.min.js');
        $this->assertFileExists(FS_FOLDER . '/view/js/jquery.autocomplete.min.js');
    }
}

/**
 * Minimal fsc stub covering every property/method header.html.twig touches.
 * Loops over menus, folders, pages, changes and extensions render empty, so
 * the template compiles and renders hermetically without a database.
 */
class StubHeaderController
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
