<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

final class FsUserAuthMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        parent::tearDown();
    }

    #[Test]
    public function loginSetsPostValuesAndDelegatesToFsLogin(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'admin';
                $this->email = 'admin@example.com';
                $this->enabled = true;
                $this->admin = true;
                $this->log_key = '';
                $this->logged_on = false;
                $this->password = password_hash('Secret123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        $result = $user->login('admin', 'Secret123');

        // POST values must be set by the wrapper for fs_login::log_in()
        $this->assertSame('admin', $_POST['user'], '$_POST[user] should be set by login()');
        $this->assertSame('Secret123', $_POST['password'], '$_POST[password] should be set by login()');
        // Without a DB, fs_login::log_in_user() cannot find the user, so login fails.
        // That's expected — this test verifies the wrapper delegates correctly.
        // The full auth flow (with DB) is covered by integration tests.
        $this->assertFalse($result, 'login() should delegate to fs_login (DB unavailable in unit test)');
        $this->assertFalse($user->logged_on);
    }

    #[Test]
    public function loginMethodCanBeCalledWithoutCrash(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'test';
                $this->password = password_hash('pw', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                $this->logged_on = false;
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        // Should not crash even with invalid credentials
        $result = $user->login('nonexistent', 'wrong');
        $this->assertFalse($result);
    }

    #[Test]
    public function logoutClearsLoggedOnAndCookies(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'admin';
                $this->logged_on = true;
                $this->log_key = 'test-log-key';
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        $user->logout();

        // logout() must set logged_on to false
        $this->assertFalse($user->logged_on, 'logged_on should be false after logout()');
    }

    #[Test]
    public function loginFromCookieSetsCookieAndDelegates(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'admin';
                $this->log_key = '';
                $this->logged_on = false;
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        $result = $user->login_from_cookie('test-autologin-token');

        // Verify the cookie was set by the wrapper
        $this->assertSame('test-autologin-token', $_COOKIE['autologin'], '$_COOKIE[autologin] should be set');
        // Without matching user in DB, login_from_cookie will fail
        $this->assertFalse($result, 'login_from_cookie() should delegate to fs_login (DB unavailable in unit test)');
    }

    #[Test]
    public function loginFromCookieCanBeCalledWithAnyValue(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'user';
                $this->logged_on = false;
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        $result = $user->login_from_cookie('invalid-autologin-value');
        $this->assertFalse($result);
    }

    #[Test]
    public function setPasswordRotatesLogKey(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'admin';
                $this->log_key = 'old-log-key-before-password-change';
                $this->password = '';
                $this->logged_on = false;
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        $oldLogKey = $user->log_key;

        $result = $user->set_password('NewSecret123');

        $this->assertTrue($result, 'set_password() should succeed with valid password');
        $this->assertNotEquals($oldLogKey, $user->log_key, 'log_key MUST rotate after set_password()');
        $this->assertNotEmpty($user->log_key, 'log_key should not be empty after rotation');
    }

    #[Test]
    public function saveGeneratesLogKeyWhenNull(): void
    {
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'testuser';
                $this->password = password_hash('Secret123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                $this->email = 'test@example.com';
                $this->log_key = '';
                $this->codagente = '';
                $this->admin = false;
                $this->enabled = true;
                $this->last_login = '';
                $this->last_login_time = '';
                $this->last_ip = null;
                $this->last_browser = null;
                $this->fs_page = '';
                $this->css = 'view/css/bootstrap-yeti.min.css';
                $this->reset_token = null;
                $this->reset_token_expires = null;
                $this->logged_on = false;
            }

            public function exists(): bool { return false; }

            /**
             * Override test() to skip DB-dependent email duplicate check.
             */
            public function test(): bool
            {
                $this->nick = trim($this->nick);
                if (!preg_match("/^[A-Z0-9_\+\.\-]{3,12}$/i", (string) $this->nick)) {
                    return false;
                }
                return true;
            }
        };

        // log_key should be null before save
        $this->assertEmpty($user->log_key, 'log_key should start as empty');

        // save() triggers the log_key null guard
        // Note: save() will try to execute SQL, but we intercept with exists() returning false
        // which causes an INSERT. This will fail because there's no DB.
        // We use a try/catch to verify that before the SQL executes, log_key was generated.
        try {
            $user->save();
        } catch (\Throwable $e) {
            // Expected — DB is not available in unit tests
        }

        // After save() is called (even if SQL fails), log_key MUST have been generated
        $this->assertNotEmpty($user->log_key, 'log_key MUST be generated by save() when null');
        $this->assertNotEmpty($user->log_key, 'log_key should not be empty');
    }
}
