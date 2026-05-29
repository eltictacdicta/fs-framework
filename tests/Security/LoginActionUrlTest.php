<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/login.php';

final class LoginActionUrlTest extends TestCase
{
    #[Test]
    public function loginActionUrlPreservesPageParam(): void
    {
        $request = Request::create('/index.php?page=login&nlogin=', 'GET');

        $controller = new class($request) extends \login {
            public function __construct(Request $request)
            {
                $this->request = $request;
            }
        };

        $url = $controller->loginActionUrl();

        $this->assertStringContainsString('page=login', $url);
    }

    #[Test]
    public function loginActionUrlRemovesLogoutParam(): void
    {
        $request = Request::create('/index.php?page=login&logout=1&nlogin=', 'GET');

        $controller = new class($request) extends \login {
            public function __construct(Request $request)
            {
                $this->request = $request;
            }
        };

        $url = $controller->loginActionUrl();

        $this->assertStringNotContainsString('logout', $url);
    }

    #[Test]
    public function loginActionUrlIncludesNloginParam(): void
    {
        $request = Request::create('/index.php?page=login&nlogin=', 'GET');

        $controller = new class($request) extends \login {
            public function __construct(Request $request)
            {
                $this->request = $request;
            }
        };

        $url = $controller->loginActionUrl();

        $this->assertStringContainsString('nlogin=', $url);
    }

    #[Test]
    public function loginActionUrlPreservesOtherQueryParams(): void
    {
        $request = Request::create('/index.php?page=login&nlogin=&foo=bar', 'GET');

        $controller = new class($request) extends \login {
            public function __construct(Request $request)
            {
                $this->request = $request;
            }
        };

        $url = $controller->loginActionUrl();

        $this->assertStringContainsString('foo=bar', $url);
    }
}
