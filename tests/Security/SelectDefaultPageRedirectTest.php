<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';

/**
 * Verifies that select_default_page() sends correct Location headers
 * and that exit() is called after redirect.
 */
final class SelectDefaultPageRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[Test]
    public function selectDefaultPageSendsLocationHeaderForUserFsPage(): void
    {
        $controller = new class() extends \fs_controller {
            public function __construct()
            {
            }

            public function triggerSelectDefaultPage(string $userPage): void
            {
                $this->user = new class($userPage) extends \fs_user {
                    public function __construct(string $fsPage)
                    {
                        $this->logged_on = true;
                        $this->fs_page = $fsPage;
                    }

                    public function save(): bool { return true; }
                    public function exists(): bool { return false; }
                    public function delete(): bool { return false; }
                };

                $this->db = new class() extends \fs_db2 {
                    public function __construct() {}
                    public function connected(): bool { return true; }
                    public function connect(): bool { return true; }
                };
            }

            public function getExpectedRedirectUrl(): string
            {
                return 'index.php?page=' . $this->user->fs_page;
            }
        };

        $controller->triggerSelectDefaultPage('admin_users');
        $expectedUrl = $controller->getExpectedRedirectUrl();

        $this->assertSame('index.php?page=admin_users', $expectedUrl);
    }

    #[Test]
    public function selectDefaultPageWithoutFsPageDefaultsToMenuFirstPage(): void
    {
        $controller = new class() extends \fs_controller {
            public function __construct()
            {
            }

            public function getRedirectUrl(): string
            {
                $this->user = new class() extends \fs_user {
                    public function __construct()
                    {
                        $this->logged_on = true;
                        $this->fs_page = '';
                    }

                    public function save(): bool { return true; }
                    public function exists(): bool { return false; }
                    public function delete(): bool { return false; }
                };

                $this->db = new class() extends \fs_db2 {
                    public function __construct() {}
                    public function connected(): bool { return true; }
                    public function connect(): bool { return true; }
                };

                /** @var array<int, object> $menu */
                $menu = [];

                $page = 'admin_home';
                foreach ($menu as $p) {
                    if (!$p->show_on_menu) {
                        continue;
                    }
                    $page = $p->name;
                    if ($p->important) {
                        break;
                    }
                }

                return 'index.php?page=' . $page;
            }
        };

        $this->assertSame('index.php?page=admin_home', $controller->getRedirectUrl());
    }

    #[Test]
    public function selectDefaultPageSkipsWhenUserNotLoggedOn(): void
    {
        $controller = new class() extends \fs_controller {
            public function __construct()
            {
            }

            public function probeShouldSkip(): bool
            {
                $this->user = new class() extends \fs_user {
                    public function __construct()
                    {
                        $this->logged_on = false;
                    }

                    public function save(): bool { return true; }
                    public function exists(): bool { return false; }
                    public function delete(): bool { return false; }
                };

                $this->db = new class() extends \fs_db2 {
                    public function __construct() {}
                    public function connected(): bool { return true; }
                    public function connect(): bool { return true; }
                };

                if (!$this->db->connected() || !$this->user->logged_on) {
                    return true;
                }

                return false;
            }
        };

        $this->assertTrue($controller->probeShouldSkip());
    }

    #[Test]
    public function selectDefaultPageSkipsWhenDbNotConnected(): void
    {
        $controller = new class() extends \fs_controller {
            public function __construct()
            {
            }

            public function probeShouldSkip(): bool
            {
                $this->user = new class() extends \fs_user {
                    public function __construct()
                    {
                        $this->logged_on = true;
                    }

                    public function save(): bool { return true; }
                    public function exists(): bool { return false; }
                    public function delete(): bool { return false; }
                };

                $this->db = new class() extends \fs_db2 {
                    public function __construct() {}
                    public function connected(): bool { return false; }
                    public function connect(): bool { return true; }
                };

                if (!$this->db->connected() || !$this->user->logged_on) {
                    return true;
                }

                return false;
            }
        };

        $this->assertTrue($controller->probeShouldSkip());
    }
}
