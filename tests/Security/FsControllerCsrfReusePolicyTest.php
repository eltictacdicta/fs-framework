<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/plugins/OidcProvider/controller/admin_oidc_diagnostics.php';

final class FsControllerCsrfReusePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $this->resetCsrfState();
    }

    protected function tearDown(): void
    {
        $this->resetCsrfState();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testDiagnosticsAjaxAllowsReusingSameCsrfTokenAcrossSteps(): void
    {
        $token = CsrfManager::generateToken();

        $controller = (new \ReflectionClass(\admin_oidc_diagnostics::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            'ajax_diagnostic_step' => '1',
            CsrfManager::FIELD_NAME => $token,
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('admin_oidc_diagnostics'));
        $this->setProperty($controller, 'class_name', 'admin_oidc_diagnostics');

        $method = new \ReflectionMethod(\fs_controller::class, 'validateCsrf');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller));
        $this->assertTrue($method->invoke($controller));
        $this->assertTrue($controller->isCsrfValid());
    }

    private function setProperty(object $instance, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function resetCsrfState(): void
    {
        $ref = new \ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}