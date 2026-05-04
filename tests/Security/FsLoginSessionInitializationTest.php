<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

final class FsLoginSessionInitializationTest extends TestCase
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

    public function testConstructorReusesSessionManagerSessionWhenAvailable(): void
    {
        $manager = SessionManager::getInstance();
        $manager->getSymfonySession()->set('probe', 'shared');

        $login = new \fs_login();

        $property = new \ReflectionProperty(\fs_login::class, 'session');
        $property->setAccessible(true);

        $loginSession = $property->getValue($login);

        $this->assertInstanceOf(Session::class, $loginSession);
        $this->assertSame($manager->getSymfonySession(), $loginSession);
        $this->assertSame('shared', $loginSession->get('probe'));
    }
}