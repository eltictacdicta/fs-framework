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

final class FsLoginPasswordVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        parent::tearDown();
    }

    public function testLogInUserDoesNotRehashAlignedArgon2idHash(): void
    {
        $user = new class() {
            public string $nick = 'demo';
            public string $email = 'demo@example.com';
            public bool $enabled = true;
            public bool $admin = false;
            public string $log_key = 'logkey';
            public bool $logged_on = false;
            public string $password;
            public int $setPasswordCalls = 0;
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->password = password_hash('Secret123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
            }

            public function set_password($password): bool
            {
                $this->setPasswordCalls++;
                $this->password = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                return true;
            }

            public function new_logkey(): void
            {
                $this->logged_on = true;
                $this->log_key = 'rotated';
            }

            public function save(): bool
            {
                $this->saveCalls++;
                return true;
            }
        };

        $result = $this->invokeLogInUser($user, 'Secret123');

        $this->assertTrue($result['result']);
        $this->assertSame(0, $user->setPasswordCalls);
        $this->assertSame(1, $user->saveCalls);
    }

    public function testLogInUserRehashesLegacyBcryptHashBeforeCompletingLogin(): void
    {
        $user = new class() {
            public string $nick = 'demo';
            public string $email = 'demo@example.com';
            public bool $enabled = true;
            public bool $admin = false;
            public string $log_key = 'logkey';
            public bool $logged_on = false;
            public string $password;
            public int $setPasswordCalls = 0;
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->password = password_hash('Secret123', PASSWORD_BCRYPT);
            }

            public function set_password($password): bool
            {
                $this->setPasswordCalls++;
                $this->password = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                return true;
            }

            public function new_logkey(): void
            {
                $this->logged_on = true;
                $this->log_key = 'rotated';
            }

            public function save(): bool
            {
                $this->saveCalls++;
                return true;
            }
        };

        $result = $this->invokeLogInUser($user, 'Secret123');

        $this->assertTrue($result['result']);
        $this->assertSame(1, $user->setPasswordCalls);
        $this->assertSame(2, $user->saveCalls);
        $this->assertStringStartsWith('$argon2id$', $user->password);
    }

    private function invokeLogInUser(object $user, string $password): array
    {
        $login = new \fs_login();
        $this->setPrivateProperty($login, 'user_model', new class($user) {
            public function __construct(private object $user)
            {
            }

            public function get(string $nick): object
            {
                return $this->user;
            }

            public function clean_cache(bool $force): void
            {
            }
        });
        $this->setPrivateProperty($login, 'core_log', new class {
            public function new_error(string $message): void
            {
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
            }
        });
        $this->setPrivateProperty($login, 'cache', new class {
            public function clean(): void
            {
            }
        });
        $this->setPrivateProperty($login, 'ip_filter', new class {
            public function in_white_list(string $ip): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });

        $controllerUser = (object) ['logged_on' => false];
        $method = new \ReflectionMethod(\fs_login::class, 'log_in_user');
        $method->setAccessible(true);

        return [
            'result' => $method->invokeArgs($login, [&$controllerUser, 'demo', $password, '127.0.0.1']),
            'controllerUser' => $controllerUser,
        ];
    }

    private function setPrivateProperty(object $instance, string $propertyName, object $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}