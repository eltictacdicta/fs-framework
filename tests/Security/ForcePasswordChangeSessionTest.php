<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/force_password_change.php';

final class ForcePasswordChangeSessionTest extends TestCase
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

    public function testGetSessionReusesSessionManagerSessionWhenAvailable(): void
    {
        $manager = SessionManager::getInstance();
        $manager->getSymfonySession()->set('force_password_change_reason', 'shared');

        $controller = (new \ReflectionClass(\force_password_change::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(\force_password_change::class, 'getSession');
        $method->setAccessible(true);
        $session = $method->invoke($controller);

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame($manager->getSymfonySession(), $session);
        $this->assertSame('shared', $session->get('force_password_change_reason'));
    }
}