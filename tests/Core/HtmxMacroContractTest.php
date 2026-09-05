<?php

declare(strict_types=1);

/**
 * Contract tests for the opt-in htmx 4 boot macro (HCS-03/04/05).
 *
 * The macro is the ONLY loading point for htmx in the framework:
 * boot() must emit the nonce'd vendored script tag plus an inline
 * bootstrap that sets inherited hx-headers (X-CSRF-TOKEN) on the
 * document element BEFORE htmx initializes (design decisions D2/D7).
 * Global header/footer templates must stay htmx-free (HCS-03).
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class HtmxMacroContractTest extends TestCase
{
    private const STUB_TOKEN = 'testcsrftokenabc123';
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    private function makeTwig(): Environment
    {
        // Read-only FilesystemLoader rooted at the theme view directory,
        // mirroring how Html::render() resolves theme templates.
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));

        // Stubs mirroring production registration (src/Core/Html.php:253-285):
        // csrf_token() -> CsrfManager::generateToken(), csp_nonce_attr() -> raw HTML attr.
        $twig->addFunction(new TwigFunction('csrf_token', fn(): string => self::STUB_TOKEN));
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
            "{% import 'Macro/Htmx.html.twig' as htmx %}{{ htmx.boot() }}"
        );

        return $caller->render();
    }

    // =====================================================================
    // HCS-05: script tag contract
    // =====================================================================

    #[Test]
    public function bootEmitsExactlyOneHtmxScriptTag(): void
    {
        $output = $this->renderBoot();

        $this->assertSame(
            1,
            substr_count($output, '<script src="view/js/htmx.min.js"'),
            'boot() must emit exactly one htmx.min.js script tag'
        );
    }

    #[Test]
    public function htmxScriptTagCarriesNonceAndDefer(): void
    {
        $output = $this->renderBoot();

        $this->assertMatchesRegularExpression(
            '/<script src="view\/js\/htmx\.min\.js" nonce="test-nonce" defer><\/script>/',
            $output
        );
    }

    #[Test]
    public function everyEmittedScriptCarriesTheCspNonce(): void
    {
        // Two scripts: the inline bootstrap + the vendored htmx asset.
        $output = $this->renderBoot();

        $this->assertSame(2, substr_count($output, self::STUB_NONCE));
    }

    // =====================================================================
    // HCS-05 / D2: root inherited-headers bootstrap
    // =====================================================================

    #[Test]
    public function bootstrapSetsInheritedHeadersOnDocumentElement(): void
    {
        $output = $this->renderBoot();

        $this->assertMatchesRegularExpression(
            "/document\.documentElement\.setAttribute\(\s*'hx-headers:inherited'/",
            $output
        );
    }

    #[Test]
    public function inheritedHeadersCarryCsrfHeaderAndToken(): void
    {
        $output = $this->renderBoot();

        $this->assertStringContainsString("'X-CSRF-TOKEN'", $output);
        $this->assertStringContainsString(self::STUB_TOKEN, $output);
    }

    #[Test]
    public function bootstrapRunsBeforeHtmxAssetLoads(): void
    {
        // The bootstrap must appear before the deferred script tag so the
        // inherited headers are set before htmx initializes (D2).
        $output = $this->renderBoot();
        $bootstrapPos = strpos($output, "document.documentElement.setAttribute");
        $scriptPos = strpos($output, '<script src="view/js/htmx.min.js"');

        $this->assertNotFalse($bootstrapPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($scriptPos, $bootstrapPos);
    }

    // =====================================================================
    // HCS-03: global templates stay htmx-free
    // =====================================================================

    #[Test]
    public function globalHeaderTemplateHasNoHtmxReference(): void
    {
        $content = file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/header.html.twig');

        $this->assertNotFalse($content);
        $this->assertSame(false, stripos((string) $content, 'htmx'));
    }

    #[Test]
    public function globalFooterTemplateHasNoHtmxReference(): void
    {
        $content = file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/footer.html.twig');

        $this->assertNotFalse($content);
        $this->assertSame(false, stripos((string) $content, 'htmx'));
    }
}
