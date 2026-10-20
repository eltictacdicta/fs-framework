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

/**
 * Verifies that invalid CSRF tokens block private_core execution in strict mode.
 */
final class FsControllerCsrfBlockingTest extends TestCase
{
    private bool $hadCsrfSoft = false;
    private bool $csrfSoftValue = false;

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        if (defined('FS_CSRF_SOFT')) {
            $this->hadCsrfSoft = true;
            $this->csrfSoftValue = FS_CSRF_SOFT;
        }

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

    public function testInvalidCsrfTokenBlocksPrivateCoreInStrictMode(): void
    {
        if ($this->hadCsrfSoft && $this->csrfSoftValue) {
            $this->markTestSkipped('FS_CSRF_SOFT is enabled in config.php');
        }

        $controller = new class() extends \fs_controller {
            public function __construct()
            {
                // Skip full fs_controller bootstrap — test validateCsrf + guard only.
            }

            public function runCsrfGuard(): bool
            {
                $this->request = Request::create('/index.php?page=test', 'POST', [
                    CsrfManager::FIELD_NAME => 'invalid-token-value',
                ]);
                $this->class_name = 'test_controller';
                $this->core_log = new \fs_core_log('test_controller');

                return $this->validateCsrfPublic();
            }

            protected function validateCsrfPublic(): bool
            {
                $ref = new \ReflectionMethod(\fs_controller::class, 'validateCsrf');
                $ref->setAccessible(true);

                return (bool) $ref->invoke($this);
            }
        };

        $this->assertFalse($controller->runCsrfGuard());
        $this->assertFalse($controller->isCsrfValid());
    }

    public function testRequireCsrfDoesNotRevalidateConsumedToken(): void
    {
        $token = CsrfManager::generateToken();

        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => $token,
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $validate = new \ReflectionMethod(\fs_controller::class, 'validateCsrf');
        $validate->setAccessible(true);
        $this->assertTrue($validate->invoke($controller));

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);
        $this->assertTrue($require->invoke($controller));
    }

    /**
     * `requireCsrf()` used to defer to `isCsrfValid()`, whose backing flag
     * starts at TRUE: a POST handler that ran before any validation (public_core,
     * an AJAX endpoint) skipped CSRF entirely. Without prior validation the
     * guard must validate now.
     */
    public function testRequireCsrfFailsClosedWithoutPriorValidation(): void
    {
        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => 'invalid-token-value',
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);

        $this->assertFalse($require->invoke($controller));
        $this->assertFalse($controller->isCsrfValid());
    }

    public function testRequireCsrfAcceptsValidTokenWithoutPriorValidation(): void
    {
        $token = CsrfManager::generateToken();

        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => $token,
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);

        $this->assertTrue($require->invoke($controller));
        $this->assertTrue($controller->isCsrfValid());
    }

    /**
     * Login-CSRF: a credential POST with an invalid token must not reach the
     * login machinery. `login_tools` is intentionally left null here, so any
     * execution past the CSRF guard raises an Error instead of returning false.
     */
    public function testLoginPostWithInvalidTokenDoesNotRunLogin(): void
    {
        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            'user' => 'admin',
            'password' => 'secret',
            CsrfManager::FIELD_NAME => 'invalid-token-value',
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $logIn = new \ReflectionMethod(\fs_controller::class, 'log_in');
        $logIn->setAccessible(true);

        $this->assertFalse($logIn->invoke($controller));
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
