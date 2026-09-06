<?php

declare(strict_types=1);

/**
 * Pilot contract: admin_user extension tabs migrated from FSAjaxLoader
 * (data-ajax-url) to declarative htmx, fully client-side, zero server
 * changes (HCS-10/11/12, jQuery migration plan final item).
 *
 * Macro side is verified by rendering (same recipe as
 * HtmxMacroContractTest); the template side uses source assertions.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class HtmxAdminUserPilotTest extends TestCase
{
    private const STUB_TOKEN = 'testcsrftokenabc123';
    private const STUB_NONCE = 'nonce="test-nonce"';
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    // Exact legacy data-ajax-url value (D3: URL parity incl. &ajax=1).
    private const LEGACY_URL = 'index.php?page={{ value.from }}{{ value.params }}&snick={{ fsc.suser.nick }}&ajax=1';

    private function makeTwig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));

        // Stubs mirroring production registration (src/Core/Html.php).
        $twig->addFunction(new TwigFunction('csrf_token', fn(): string => self::STUB_TOKEN));
        $twig->addFunction(new TwigFunction(
            'csp_nonce_attr',
            fn(): string => self::STUB_NONCE,
            ['is_safe' => ['html']]
        ));

        return $twig;
    }

    private function renderBoot(array $config = []): string
    {
        // json_encode output is valid Twig hash syntax ({"allowScriptTags":false}).
        $call = $config === []
            ? '{{ htmx.boot() }}'
            : '{{ htmx.boot(' . json_encode($config) . ') }}';

        $caller = $this->makeTwig()->createTemplate(
            "{% import 'Macro/Htmx.html.twig' as htmx %}" . $call
        );

        return $caller->render();
    }

    private function adminUserTemplate(): string|false
    {
        return file_get_contents(FS_FOLDER . self::THEME_VIEW_DIR . '/admin_user.html.twig');
    }

    // =====================================================================
    // HCS-12 / D4: config form emits meta + scrubber before the asset
    // =====================================================================

    #[Test]
    public function configFormEmitsHtmxConfigMetaWithAllowScriptTagsFalse(): void
    {
        $output = $this->renderBoot(['allowScriptTags' => false]);

        $this->assertMatchesRegularExpression(
            '/<meta name="htmx-config" content=\'[^\']*\'\s*>/',
            $output
        );

        // Attribute is HTML-escaped; decode to check the JSON payload.
        preg_match('/<meta name="htmx-config" content=\'([^\']*)\'/', $output, $m);
        $this->assertSame('{"allowScriptTags":false}', html_entity_decode($m[1]));
    }

    #[Test]
    public function configFormEmitsMetaAndScrubberBeforeHtmxAsset(): void
    {
        $output = $this->renderBoot(['allowScriptTags' => false]);

        $metaPos = strpos($output, '<meta name="htmx-config"');
        $scrubberPos = strpos($output, 'htmx:before:swap');
        $assetPos = strpos($output, '<script src="view/js/htmx.min.js"');

        $this->assertNotFalse($metaPos);
        $this->assertNotFalse($scrubberPos);
        $this->assertNotFalse($assetPos);
        $this->assertLessThan($assetPos, $metaPos);
        $this->assertLessThan($assetPos, $scrubberPos);
    }

    #[Test]
    public function configFormScrubberMirrorsFSAjaxLoaderSanitizeHtml(): void
    {
        $output = $this->renderBoot(['allowScriptTags' => false]);

        // Dangerous tag set stripped before insertion (scripts allowed
        // forms — FSAjaxLoader's loadExtensionTab uses allowForms: true).
        $this->assertStringContainsString("querySelectorAll('script,object,embed,applet,iframe')", $output);
        // on* handlers and javascript: URLs removed from all elements.
        $this->assertStringContainsString("name.indexOf('on') === 0", $output);
        $this->assertStringContainsString("'javascript:'", $output);
    }

    #[Test]
    public function configFormAddsOneMoreNonceCarryingInlineScript(): void
    {
        // Baseline: bootstrap + asset = 2. Config form adds the scrubber: 3.
        $output = $this->renderBoot(['allowScriptTags' => false]);

        $this->assertSame(3, substr_count($output, self::STUB_NONCE));
    }

    #[Test]
    public function configFormStillEmitsCsrfBootstrapAndSingleAsset(): void
    {
        $output = $this->renderBoot(['allowScriptTags' => false]);

        $this->assertStringContainsString("'hx-headers:inherited'", $output);
        $this->assertSame(
            1,
            substr_count($output, '<script src="view/js/htmx.min.js"'),
            'boot() must emit exactly one htmx.min.js script tag'
        );
    }

    #[Test]
    public function zeroArgFormEmitsNoConfigArtifacts(): void
    {
        $output = $this->renderBoot();

        $this->assertStringNotContainsString('htmx-config', $output);
        $this->assertStringNotContainsString('htmx:before:swap', $output);
    }

    // =====================================================================
    // HCS-10 / D1-D3, D5: template wiring
    // =====================================================================

    #[Test]
    public function extensionTabLinksUseHtmxGetWithExactLegacyUrl(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // D3: byte-exact URL parity with the old data-ajax-url (incl. ajax=1).
        $this->assertStringContainsString('hx-get="' . self::LEGACY_URL . '"', $content);
    }

    #[Test]
    public function extensionTabLinksHaveNoDataAjaxUrl(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // D1: removal prevents FSAjaxLoader's shown.bs.tab double-load.
        $this->assertStringNotContainsString('data-ajax-url', $content);
    }

    #[Test]
    public function extensionTabLinkKeepsBootstrapTabToggle(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // D1: Bootstrap still switches the panel via data-toggle="tab".
        $this->assertMatchesRegularExpression(
            '/<a href="#ext_\{\{ value\.name \}\}" aria-controls="ext_\{\{ value\.name \}\}" role="tab" data-toggle="tab" hx-get=/',
            $content
        );
    }

    #[Test]
    public function extensionTabLinkTargetsPaneWithInnerHtmlAndClickOnce(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        $this->assertStringContainsString('hx-target="#ext_{{ value.name }}"', $content);
        $this->assertStringContainsString('hx-swap="innerHTML"', $content);
        $this->assertStringContainsString('hx-trigger="click once"', $content);
    }

    #[Test]
    public function extensionTabLinkSelectsWrapperChildren(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // HCS-11: single selector mirroring FSAjaxLoader's effective
        // extraction (the shell has no .content element; a comma list
        // would duplicate matches under htmx 4's querySelectorAll).
        $this->assertStringContainsString('hx-select=".content-wrapper > *"', $content);
    }

    #[Test]
    public function templateBootsHtmxWithScriptSanitization(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // D5: page-level opt-in via the macro (HCS-04 load path).
        $this->assertStringContainsString("{% import 'Macro/Htmx.html.twig' as htmx %}", $content);
        $this->assertStringContainsString("{{ htmx.boot({'allowScriptTags': false}) }}", $content);
    }

    #[Test]
    public function extensionTabPanesRemainUntouched(): void
    {
        $content = $this->adminUserTemplate();
        $this->assertNotFalse($content);

        // The placeholder panes keep their markup (spinner until first swap).
        $this->assertStringContainsString('id="ext_{{ value.name }}" data-ajax-src=', $content);
    }
}
