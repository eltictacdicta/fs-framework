<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Security;

use FSFramework\Security\CookieSigner;
use FSFramework\Security\SafeRedirect;
use FSFramework\Security\UserAdapter;
use PHPUnit\Framework\TestCase;

class SecurityHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        SafeRedirect::clearAllowedHosts();
        unset($_REQUEST['redirect'], $_SERVER['HTTP_HOST']);
        parent::tearDown();
    }

    public function testCookieSignerSignsAndVerifiesRememberMePayloads(): void
    {
        $signature = CookieSigner::signRememberMe('demo', 'log-key');

        $this->assertSame('auth_sig', CookieSigner::SIGNATURE_COOKIE);
        $this->assertNotSame('', $signature);
        $this->assertTrue(CookieSigner::verifyRememberMe('demo', 'log-key', $signature));
        $this->assertFalse(CookieSigner::verifyRememberMe('demo', 'other', $signature));
        $this->assertFalse(CookieSigner::verifyRememberMe('demo', 'log-key', ''));
    }

    public function testSafeRedirectManagesAllowedHostsAndSanitizesUrls(): void
    {
        $_SERVER['HTTP_HOST'] = 'app.local';

        SafeRedirect::addAllowedHosts(['trusted.example', 'trusted.example', 'cdn.example']);

        $this->assertSame(['trusted.example', 'cdn.example'], SafeRedirect::getAllowedHosts());
        $this->assertSame('ventas/lista?foo=1', SafeRedirect::validate('../ventas/lista?foo=1'));
        $this->assertSame('index.php', SafeRedirect::validate('javascript:alert(1)'));
        $this->assertSame('https://trusted.example/path', SafeRedirect::validate('https://trusted.example/path'));

        $_REQUEST['redirect'] = 'https://sub.app.local/dashboard';
        $this->assertSame('https://sub.app.local/dashboard', SafeRedirect::getFromRequest());

        SafeRedirect::clearAllowedHosts();
        $this->assertSame([], SafeRedirect::getAllowedHosts());
    }

    public function testUserAdapterExposesLegacyUserCapabilities(): void
    {
        $legacyUser = new class() {
            public string $nick = 'admin';
            public bool $admin = false;
            public bool $enabled = true;
            public bool $logged_on = true;
            public string $password = 'hash';
            public string $email = 'admin@example.com';
            public string $codagente = 'AG01';
            public string $fs_page = 'admin_home';
            public string $last_ip = '127.0.0.1';

            public function get_accesses(): array
            {
                return [(object) ['fs_page' => 'ventas', 'allow_delete' => true]];
            }

            public function have_access_to(string $pageName): bool
            {
                return $pageName === 'ventas';
            }

            public function show_last_login(): string
            {
                return '2026-04-19 10:00:00';
            }

            public function greet(string $name): string
            {
                return 'Hola ' . $name;
            }
        };

        $adapter = new UserAdapter($legacyUser);

        $this->assertSame($legacyUser, $adapter->getLegacyUser());
        $this->assertSame('admin', $adapter->getUserIdentifier());
        $this->assertSame('hash', $adapter->getPassword());
        $this->assertTrue($adapter->isEnabled());
        $this->assertTrue($adapter->isLoggedIn());
        $this->assertTrue($adapter->hasAccessTo('ventas'));
        $this->assertTrue($adapter->canDeleteIn('ventas'));
        $this->assertSame('admin@example.com', $adapter->getEmail());
        $this->assertSame('AG01', $adapter->getCodAgente());
        $this->assertSame('admin_home', $adapter->getHomePage());
        $this->assertSame('127.0.0.1', $adapter->getLastIp());
        $this->assertSame('2026-04-19 10:00:00', $adapter->getLastLogin());
        $this->assertContains('ROLE_PAGE_VENTAS', $adapter->getRoles());
        $this->assertContains('ROLE_DELETE_VENTAS', $adapter->getRoles());
        $this->assertSame('admin@example.com', $adapter->__get('email'));
        $this->assertTrue($adapter->__isset('email'));
        $this->assertSame('Hola FS', $adapter->__call('greet', ['FS']));
        $this->assertTrue($adapter->isEqualTo(new UserAdapter($legacyUser)));
    }

    public function testUserAdapterCanBeCreatedFromLegacyNickLookup(): void
    {
        $this->loadLegacyUserModel();

        $nick = 'adapter' . substr(bin2hex(random_bytes(4)), 0, 5);
        $user = new \fs_user();
        $user->nick = $nick;
        $user->email = $nick . '@example.test';
        $user->enabled = true;
        $user->admin = false;

        if (!$user->set_password('AdapterTest123')) {
            $this->markTestSkipped('Could not prepare legacy user fixture password.');
        }

        if (!$user->save()) {
            $this->markTestSkipped('Could not persist legacy user fixture.');
        }

        try {
            $adapter = UserAdapter::fromNick($nick);

            $this->assertInstanceOf(UserAdapter::class, $adapter);
            $this->assertSame($nick, $adapter->getUserIdentifier());
            $this->assertNull(UserAdapter::fromNick('missing'));
        } finally {
            $persistedUser = (new \fs_user())->get($nick);
            if ($persistedUser) {
                $persistedUser->delete();
            }
        }
    }

    private function loadLegacyUserModel(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_user.php';
    }
}
