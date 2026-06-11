<?php
declare(strict_types=1);

/**
 * Tests that fs_auth::verifyCsrfRequest() reads CSRF tokens via
 * Symfony Request (Kernel::request()) instead of raw $_POST/$_SERVER.
 *
 * Spec: critical-security-fixes-2026-03, Requirement H2
 */

namespace Tests\Base;

use FSFramework\Core\Kernel;
use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(\fs_auth::class)]
class FsAuthCsrfRequestTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_auth.php';

        // Reset Kernel singleton so each test controls boot state
        $this->resetKernel();

        // Reset session/CSRF state
        SessionManager::reset();
        $this->resetCsrfState();

        // Clean superglobals
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->resetKernel();
        SessionManager::reset();
        $this->resetCsrfState();
    }

    // =====================================================================
    // H2-1: POST form submits valid CSRF token
    // =====================================================================

    #[Test]
    public function postTokenIsReadFromSymfonyRequest(): void
    {
        $token = CsrfManager::generateToken();
        $_POST['_token'] = $token;

        // Boot kernel — it reads Request from globals (including $_POST)
        Kernel::boot();

        $this->assertTrue(\fs_auth::verifyCsrfRequest());
    }

    // =====================================================================
    // H2-2: AJAX request sends CSRF token via header
    // =====================================================================

    #[Test]
    public function headerTokenIsReadWhenPostIsEmpty(): void
    {
        $token = CsrfManager::generateToken();
        // No POST _token
        $_POST = [];
        // Set the header — Symfony Request reads HTTP_ prefixed server vars
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        Kernel::boot();

        $this->assertTrue(\fs_auth::verifyCsrfRequest());
    }

    // =====================================================================
    // H2-3: Kernel not booted (edge case)
    // =====================================================================

    #[Test]
    public function nullKernelReturnsFalseWithoutError(): void
    {
        // Kernel is NOT booted (reset in setUp)
        // verifyCsrfRequest must return false and not throw
        $this->assertFalse(\fs_auth::verifyCsrfRequest());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function resetKernel(): void
    {
        $ref = new ReflectionClass(Kernel::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function resetCsrfState(): void
    {
        $ref = new ReflectionClass(CsrfManager::class);
        if ($ref->hasProperty('tokens')) {
            $prop = $ref->getProperty('tokens');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    }
}
