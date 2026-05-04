<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';

final class FsControllerSessionTouchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testPrePrivateCoreTouchesSessionForAuthenticatedUser(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'email' => 'test@example.com',
            'admin' => false,
            'logkey' => 'abc123',
        ]);

        $session = $manager->getSymfonySession();
        $session->set('last_activity', time() - 3600);
        $oldLastActivity = $session->get('last_activity');

        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'GET'));
        $this->setProperty($controller, 'user', (object) ['logged_on' => true]);
        $this->setProperty($controller, 'extensions', []);

        $method = new \ReflectionMethod(\fs_controller::class, 'pre_private_core');
        $method->setAccessible(true);
        $method->invoke($controller);

        $this->assertGreaterThan($oldLastActivity, $session->get('last_activity'));
    }

    private function setProperty(object $instance, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}