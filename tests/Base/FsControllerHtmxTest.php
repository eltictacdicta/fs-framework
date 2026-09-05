<?php

declare(strict_types=1);

/**
 * Verifies fs_controller::isHtmxRequest() (HCS-06): true iff the
 * HX-Request header is present, mirroring isAjax() semantics —
 * presence-based and value-insensitive (design decision D3).
 */

namespace Tests\Base;

use FSFramework\Core\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(\fs_controller::class)]
class FsControllerHtmxTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_controller.php';
        $this->resetKernel();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HX_REQUEST']);
        $this->resetKernel();
    }

    #[Test]
    public function hxRequestHeaderPresentReturnsTrue(): void
    {
        $_SERVER['HTTP_HX_REQUEST'] = 'true';

        Kernel::boot();

        $this->assertTrue($this->makeController()->isHtmxRequest());
    }

    #[Test]
    public function hxRequestHeaderAbsentReturnsFalse(): void
    {
        unset($_SERVER['HTTP_HX_REQUEST']);

        Kernel::boot();

        $this->assertFalse($this->makeController()->isHtmxRequest());
    }

    #[Test]
    public function hxRequestHeaderAnyValueCountsAsPresent(): void
    {
        // Presence-based (D3): the header VALUE is irrelevant, htmx always sends it.
        $_SERVER['HTTP_HX_REQUEST'] = '0';

        Kernel::boot();

        $this->assertTrue($this->makeController()->isHtmxRequest());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeController(): \fs_controller
    {
        $controller = new class() extends \fs_controller {
            public function __construct()
            {
                // Intentionally empty: skips legacy bootstrap (DB, session, menu).
            }
        };

        // Inject the booted Kernel request into the protected property,
        // reproducing what the real constructor does (fs_controller.php:195).
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
