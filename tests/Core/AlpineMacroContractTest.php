<?php

declare(strict_types=1);

/**
 * Contract tests for the opt-in Alpine.js (CSP build) boot macro
 * (ABS-01/02/03).
 *
 * The macro is the ONLY loading point for Alpine in the framework:
 * boot() must emit exactly one nonce'd, deferred <script> tag for the
 * vendored Alpine CSP asset (@alpinejs/csp, no eval/new Function, so it
 * satisfies the framework CSP). Global header/footer templates must
 * stay Alpine-free, mirroring the htmx contract (HCS-03 / ABS-02).
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class AlpineMacroContractTest extends TestCase
{
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    private function makeTwig(): Environment
    {
        // Read-only FilesystemLoader rooted at the theme view directory,
        // mirroring how Html::render() resolves theme templates.
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));

        // Stub mirroring production registration (src/Core/Html.php):
        // csp_nonce_attr() -> raw HTML attribute.
        $twig->addFunction(new TwigFunction(
            'csp_nonce_attr',
            fn(): string => self::STUB_NONCE,
            ['is_safe' => ['html']]
        ));

        return $twig;
    }

    private function renderBoot(): string
    {
        $caller = $this->makeTwig()->createTemplate(
            "{% import 'Macro/Alpine.html.twig' as alpine %}{{ alpine.boot() }}"
        );

        return $caller->render();
    }

    // =====================================================================
    // ABS-03: script tag contract
    // =====================================================================

    #[Test]
    public function bootEmitsExactlyOneAlpineScriptTag(): void
    {
        $output = $this->renderBoot();

        $this->assertSame(
            1,
            substr_count($output, '<script src="view/js/alpine-csp.min.js"'),
            'boot() must emit exactly one alpine-csp.min.js script tag'
        );
    }

    #[Test]
    public function alpineScriptTagCarriesNonceAndDefer(): void
    {
        $output = $this->renderBoot();

        $this->assertMatchesRegularExpression(
            '/<script src="view\/js\/alpine-csp\.min\.js" nonce="test-nonce" defer><\/script>/',
            $output
        );
    }

    #[Test]
    public function everyEmittedScriptCarriesTheCspNonce(): void
    {
        // One script total: no inline bootstrap, the vendored asset only.
        $output = $this->renderBoot();

        $this->assertSame(1, substr_count($output, self::STUB_NONCE));
    }

    // =====================================================================
    // CSP safety: no eval helpers, no inline event handlers
    // =====================================================================

    #[Test]
    public function macroTemplateContainsNoEvalHelpers(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/Macro/Alpine.html.twig');

        $this->assertStringNotContainsString('eval(', $source);
        $this->assertStringNotContainsString('new Function(', $source);
    }

    #[Test]
    public function macroTemplateContainsNoInlineEventHandlers(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/Macro/Alpine.html.twig');
        $output = $this->renderBoot();

        // on*= attributes (onclick=, onload=, ...) — lookahead skips the
        // legitimate nonce= attribute.
        $inlineHandler = '/\s(?!nonce=)on[a-z]+\s*=/i';

        $this->assertDoesNotMatchRegularExpression($inlineHandler, $source);
        $this->assertDoesNotMatchRegularExpression($inlineHandler, $output);
    }

    // =====================================================================
    // ABS-01: vendored asset — present, non-trivial, CSP build
    // =====================================================================

    #[Test]
    public function alpineAssetFileExistsAndIsNonTrivial(): void
    {
        $path = FS_FOLDER . '/view/js/alpine-csp.min.js';

        $this->assertFileExists($path);
        $this->assertGreaterThan(10000, (int) filesize($path));
    }

    #[Test]
    public function alpineAssetIsTheCspBuild(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/view/js/alpine-csp.min.js');

        $this->assertStringNotContainsString('eval(', $source);
        $this->assertStringNotContainsString('new Function(', $source);
    }

    // =====================================================================
    // ABS-01: build pipeline wiring
    // =====================================================================

    #[Test]
    public function packageJsonPinsTheAlpineCspDependency(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/package.json');

        $this->assertMatchesRegularExpression(
            '/"@alpinejs\/csp"\s*:\s*"3\.17\.1"/',
            $source,
            'package.json must pin the exact @alpinejs/csp version'
        );
    }

    #[Test]
    public function buildShCopiesTheAlpineCspAsset(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/build.sh');

        $this->assertStringContainsString(
            'cp node_modules/@alpinejs/csp/dist/cdn.min.js view/js/alpine-csp.min.js',
            $source
        );
    }

    // =====================================================================
    // ABS-02: global templates stay alpine-free
    // =====================================================================

    #[Test]
    public function globalHeaderTemplateHasNoAlpineReference(): void
    {
        $content = file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/header.html.twig');

        $this->assertNotFalse($content);
        $this->assertSame(false, stripos((string) $content, 'alpine'));
    }

    #[Test]
    public function globalFooterTemplateHasNoAlpineReference(): void
    {
        $content = file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/footer.html.twig');

        $this->assertNotFalse($content);
        $this->assertSame(false, stripos((string) $content, 'alpine'));
    }
}
