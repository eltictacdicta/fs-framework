<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

final class FsLoginCookieAuthTest extends TestCase
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

    public function testDisabledRememberedUserReturnsFalseExplicitly(): void
    {
        $_COOKIE['user'] = 'disabled-user';
        $_COOKIE['logkey'] = 'remember-me-key';

        $login = new \fs_login();
        $this->setPrivateProperty($login, 'user_model', new class {
            public function get(string $nick): object
            {
                return (object) ['nick' => $nick, 'enabled' => false];
            }

            public function clean_cache(bool $force): void
            {
                // No-op intencional: el test no usa caché de usuarios.
            }
        });
        $this->setPrivateProperty($login, 'core_log', new class {
            public function new_error(string $message): void
            {
                // No-op intencional: el test no valida mensajes de log.
            }

            public function set_user_nick($user): void
            {
                // No-op intencional: stub de fs_core_log.
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
                // No-op intencional: stub de fs_core_log.
            }
        });
        $this->setPrivateProperty($login, 'cache', new class {
            public function clean(): void
            {
                // No-op intencional: stub de fs_cache.
            }
        });

        $controllerUser = (object) ['logged_on' => true];
        $method = new \ReflectionMethod(\fs_login::class, 'log_in_cookie');
        $method->setAccessible(true);

        $result = $method->invokeArgs($login, [&$controllerUser]);

        $this->assertFalse($result);
    }

    private function setPrivateProperty(object $instance, string $propertyName, object $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}