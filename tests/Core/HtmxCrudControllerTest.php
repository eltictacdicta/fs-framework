<?php

declare(strict_types=1);

/**
 * HtmxCrudController unit tests (HCS-13, HCS-14, HCS-15, CRD-01).
 *
 * Tests buildFragment() (pure, Twig-free), flashPayload(), noContentWithFlash(),
 * OOB template-wrapping, and X-FS-* headers via an anonymous subclass that
 * overrides renderPartial() with fixture HTML and emit() for capture — no DB, no Twig.
 */

namespace Tests\Core;

use FSFramework\Core\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(\FSFramework\Controller\HtmxCrudController::class)]
class HtmxCrudControllerTest extends TestCase
{
    private const FIXTURE_HTML = '<tr class="familia-row"><td>F010</td></tr>';

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/src/Controller/HtmxCrudConfig.php';
        require_once FS_FOLDER . '/src/Controller/HtmxCrudController.php';
        $this->resetKernel();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HX_REQUEST']);
        $this->resetKernel();
    }

    // =====================================================================
    // buildFragment — pure, Twig-free
    // =====================================================================

    #[Test]
    public function buildFragmentReturnsPureHtmlAndHeaders(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML);

        $this->assertSame(200, $result['status']);
        $this->assertSame(self::FIXTURE_HTML, $result['html']);
        $this->assertSame('text/html; charset=UTF-8', $result['headers']['Content-Type']);
        $this->assertSame('nosniff', $result['headers']['X-Content-Type-Options']);
    }

    #[Test]
    public function buildFragmentIncludesXFsHeadersAlways(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML);

        $this->assertNotEmpty($result['headers']['X-FS-Duration']);
        $this->assertIsNumeric($result['headers']['X-FS-Queries']);
        $this->assertIsNumeric($result['headers']['X-FS-Transactions']);
    }

    #[Test]
    public function buildFragmentWith204StillHasXFsHeaders(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment('', ['status' => 204]);

        $this->assertSame(204, $result['status']);
        $this->assertArrayHasKey('X-FS-Duration', $result['headers']);
        $this->assertArrayHasKey('X-FS-Queries', $result['headers']);
        $this->assertArrayHasKey('X-FS-Transactions', $result['headers']);
    }

    // =====================================================================
    // HX-Trigger flash payload — HCS-13
    // =====================================================================

    #[Test]
    public function flashPayloadSetsHxTriggerWithCorrectShape(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => [
                'errors' => ['Bad input'],
                'messages' => ['Saved'],
                'advices' => ['Check this'],
            ],
        ]);

        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertSame(['Bad input'], $payload['fs:flash']['errors']);
        $this->assertSame(['Saved'], $payload['fs:flash']['messages']);
        $this->assertSame(['Check this'], $payload['fs:flash']['advices']);
    }

    #[Test]
    public function emptyFlashOmitsHxTriggerHeader(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => [], 'advices' => []],
        ]);

        $this->assertArrayNotHasKey('HX-Trigger', $result['headers']);
    }

    #[Test]
    public function noFlashOptionOmitsHxTriggerHeader(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML);

        $this->assertArrayNotHasKey('HX-Trigger', $result['headers']);
    }

    #[Test]
    public function flashPayloadStripsCrlfSequences(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ["Line1\r\nLine2"], 'advices' => []],
        ]);

        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertSame(['Line1Line2'], $payload['fs:flash']['messages']);
    }

    #[Test]
    public function flashPayloadUsesJsonUnescapedUnicode(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ['Guardado con exito ñ'], 'advices' => []],
        ]);

        $this->assertStringContainsString('Guardado con exito ñ', $result['headers']['HX-Trigger']);
    }

    #[Test]
    public function hxTriggerMergesFlashAndExtraEventsInOneHeader(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ['Saved'], 'advices' => []],
            'events' => ['fs:modal-close' => []],
        ]);

        // ONE header carrying both keys
        $this->assertArrayHasKey('HX-Trigger', $result['headers']);
        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertSame(['Saved'], $payload['fs:flash']['messages']);
        $this->assertArrayHasKey('fs:modal-close', $payload);
    }

    #[Test]
    public function eventsWithEmptyFlashStillEmitHxTrigger(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => [], 'advices' => []],
            'events' => ['fs:modal-close' => []],
        ]);

        $this->assertArrayHasKey('HX-Trigger', $result['headers']);
        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertArrayHasKey('fs:modal-close', $payload);
        $this->assertArrayNotHasKey('fs:flash', $payload);
    }

    // =====================================================================
    // OOB flash block — HCS-15
    // =====================================================================

    #[Test]
    public function oobFlashWithPayloadAppendsTemplateWrappedBlock(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ['Saved'], 'advices' => []],
            'oobFlash' => true,
        ]);

        $this->assertStringContainsString('<template>', $result['html']);
        $this->assertStringContainsString('hx-swap-oob="true"', $result['html']);
        $this->assertStringContainsString('id="fs-htmx-flash"', $result['html']);
    }

    #[Test]
    public function oobFlashDisabledOmitsBlock(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ['Saved'], 'advices' => []],
            'oobFlash' => false,
        ]);

        $this->assertStringNotContainsString('<template>', $result['html']);
        $this->assertStringNotContainsString('hx-swap-oob', $result['html']);
    }

    #[Test]
    public function oobFlashWithEmptyPayloadOmitsBlock(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => [], 'advices' => []],
            'oobFlash' => true,
        ]);

        $this->assertStringNotContainsString('<template>', $result['html']);
    }

    #[Test]
    public function debugOobOmittedWhenFsDebugDisabled(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ['Saved'], 'advices' => []],
            'debug' => true,
        ]);

        // FS_DEBUG is false in test bootstrap
        $this->assertStringNotContainsString('fs-htmx-debug', $result['html']);
    }

    // =====================================================================
    // noContentWithFlash — HCS-14
    // =====================================================================

    #[Test]
    public function noContentWithFlashReturns204WithEmptyBody(): void
    {
        $ctrl = $this->makeController();
        $ctrl->noContentWithFlashPublic();
        $emitted = $ctrl->lastEmitted;

        $this->assertSame(204, $emitted['status']);
        $this->assertSame('', $emitted['html']);
        $this->assertArrayHasKey('X-FS-Duration', $emitted['headers']);
        $this->assertArrayHasKey('X-FS-Queries', $emitted['headers']);
        $this->assertArrayHasKey('X-FS-Transactions', $emitted['headers']);
    }

    #[Test]
    public function noContentWithFlashOmitsHxTriggerWhenPayloadEmpty(): void
    {
        $ctrl = $this->makeController();
        $ctrl->noContentWithFlashPublic();
        $emitted = $ctrl->lastEmitted;

        // Empty payload → HX-Trigger omitted per HCS-13 spec
        $this->assertArrayNotHasKey('HX-Trigger', $emitted['headers']);
    }

    // =====================================================================
    // flashPayload — returns standardized shape
    // =====================================================================

    #[Test]
    public function flashPayloadReturnsCorrectShape(): void
    {
        $ctrl = $this->makeController();
        $payload = $ctrl->flashPayload();

        $this->assertArrayHasKey('errors', $payload);
        $this->assertArrayHasKey('messages', $payload);
        $this->assertArrayHasKey('advices', $payload);
        $this->assertIsArray($payload['errors']);
        $this->assertIsArray($payload['messages']);
        $this->assertIsArray($payload['advices']);
    }

    // =====================================================================
    // requireHtmx — HCS-14
    // =====================================================================

    #[Test]
    public function requireHtmxReturnsFalseWithoutHxRequestHeader(): void
    {
        unset($_SERVER['HTTP_HX_REQUEST']);
        Kernel::boot();
        $ctrl = $this->makeController();
        $this->assertFalse($ctrl->requireHtmx());
    }

    #[Test]
    public function requireHtmxReturnsTrueWithHxRequestHeader(): void
    {
        $_SERVER['HTTP_HX_REQUEST'] = 'true';
        Kernel::boot();
        $ctrl = $this->makeController();
        $this->assertTrue($ctrl->requireHtmx());
    }

    // =====================================================================
    // Triangulation
    // =====================================================================

    #[Test]
    public function multipleErrorsAndMessagesInPayload(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => [
                'errors' => ['Error 1', 'Error 2'],
                'messages' => ['Msg 1', 'Msg 2', 'Msg 3'],
                'advices' => ['Tip 1'],
            ],
        ]);

        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertCount(2, $payload['fs:flash']['errors']);
        $this->assertCount(3, $payload['fs:flash']['messages']);
        $this->assertCount(1, $payload['fs:flash']['advices']);
    }

    #[Test]
    public function onlyErrorsStillSetsHxTrigger(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => ['Only error'], 'messages' => [], 'advices' => []],
        ]);

        $this->assertArrayHasKey('HX-Trigger', $result['headers']);
        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertSame(['Only error'], $payload['fs:flash']['errors']);
    }

    #[Test]
    public function htmlPreservedExactly(): void
    {
        $customHtml = '<tbody id="familias-tbody"><tr data-codfamilia="F001"><td>Root</td></tr></tbody>';
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment($customHtml);

        $this->assertStringStartsWith($customHtml, $result['html']);
    }

    #[Test]
    public function stripsBareCrAndLfInFlashPayload(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->buildFragment(self::FIXTURE_HTML, [
            'flash' => ['errors' => [], 'messages' => ["A\rB", "C\nD", "E\r\nF"], 'advices' => []],
        ]);

        $payload = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertSame(['AB', 'CD', 'EF'], $payload['fs:flash']['messages']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeController(): object
    {
        Kernel::boot();

        $controller = new class(self::FIXTURE_HTML) extends \FSFramework\Controller\HtmxCrudController {
            public ?array $lastEmitted = null;
            private string $fixtureHtml;

            public function __construct(string $html = '')
            {
                $this->fixtureHtml = $html;
            }

            protected function renderPartial(string $partial, array $params = []): string
            {
                return $this->fixtureHtml;
            }

            protected function emit(array $result): void
            {
                $this->lastEmitted = $result;
            }

            public function buildFragment(string $html, array $options = []): array
            {
                return parent::buildFragment($html, $options);
            }

            public function flashPayload(): array
            {
                return ['errors' => [], 'messages' => [], 'advices' => []];
            }

            public function requireHtmx(): bool
            {
                return parent::requireHtmx();
            }

            public function noContentWithFlashPublic(): void
            {
                parent::noContentWithFlash();
            }

            public function selects(): int { return 0; }
            public function transactions(): int { return 0; }
            public function duration(): string { return '0.000 s'; }
        };

        $prop = $this->requestProperty();
        $prop->setValue($controller, Kernel::request());

        return $controller;
    }

    private function requestProperty(): ReflectionProperty
    {
        $prop = (new ReflectionClass(\fs_controller::class))->getProperty('request');
        $prop->setAccessible(true);
        return $prop;
    }

    private function resetKernel(): void
    {
        $prop = (new ReflectionClass(Kernel::class))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
