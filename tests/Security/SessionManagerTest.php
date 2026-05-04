<?php

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Security;

use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;

class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();
        SessionManager::reset();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
            }

            session_destroy();
        }

        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testGetSymfonySessionReturnsUnderlyingSession(): void
    {
        $manager = SessionManager::getInstance();

        $this->assertInstanceOf(Session::class, $manager->getSymfonySession());
    }

    public function testGetSymfonySessionUsesPhpBridgeWhenPhpSessionAlreadyActive(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        SessionManager::reset();
        $manager = SessionManager::getInstance();
        $session = $manager->getSymfonySession();
        $storageProperty = new \ReflectionProperty(Session::class, 'storage');

        $this->assertInstanceOf(Session::class, $session);
        $this->assertInstanceOf(PhpBridgeSessionStorage::class, $storageProperty->getValue($session));
    }

    public function testLoginSetsLastActivityAndLoginTime(): void
    {
        $manager = SessionManager::getInstance();
        $before = time();

        $manager->login([
            'nick' => 'testuser',
            'email' => 'test@example.com',
            'admin' => false,
            'logkey' => 'abc123',
        ]);

        $session = $manager->getSymfonySession();
        $this->assertGreaterThanOrEqual($before, $session->get('login_time'));
        $this->assertGreaterThanOrEqual($before, $session->get('last_activity'));
        $this->assertSame('testuser', $session->get('user_nick'));
    }

    public function testTouchUpdatesLastActivity(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'admin' => false,
            'logkey' => 'abc',
        ]);

        $session = $manager->getSymfonySession();
        $session->set('last_activity', time() - 3600);
        $old = $session->get('last_activity');

        $manager->touch();

        $this->assertGreaterThan($old, $session->get('last_activity'));
    }

    public function testIsValidReturnsTrueForFreshSession(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'admin' => false,
            'logkey' => 'abc',
        ]);

        $this->assertTrue($manager->isValid());
    }

    public function testIsValidReturnsFalseWhenNoUser(): void
    {
        $manager = SessionManager::getInstance();

        $this->assertFalse($manager->isValid());
    }

    public function testIsValidReturnsFalseWhenIdleExpired(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'admin' => false,
            'logkey' => 'abc',
        ]);

        $session = $manager->getSymfonySession();
        $session->set('last_activity', time() - 99999);

        $this->assertFalse($manager->isValid());
    }

    public function testLoginStoresRememberMeFlag(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'admin' => false,
            'logkey' => 'abc',
            'remember_me' => false,
        ]);

        $this->assertFalse($manager->isRememberMe());
    }

    public function testIsRememberMeDefaultsToFalse(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'admin' => false,
            'logkey' => 'abc',
        ]);

        $this->assertFalse($manager->isRememberMe());
    }

    public function testIsLoggedInReturnsFalseForEmptySession(): void
    {
        $manager = SessionManager::getInstance();

        $this->assertFalse($manager->isLoggedIn());
    }

    public function testCsrfFieldRendersModernAndLegacyTokenNames(): void
    {
        $manager = SessionManager::getInstance();

        $field = $manager->csrfField();

        $this->assertStringContainsString('name="_csrf_token"', $field);
        $this->assertStringContainsString('name="_token"', $field);
    }

    /**
     * Si un plugin registra “omitir restauración legacy”, no hay login ERP aunque existan cookies.
     */
    public function testIsLoggedInReturnsFalseWhenSkipLegacyGuardMatches(): void
    {
        $manager = SessionManager::getInstance();
        $manager->login([
            'nick' => 'testuser',
            'email' => 'test@example.com',
            'admin' => false,
            'logkey' => 'abc123',
        ]);

        LegacyAuthBridge::registerSkipLegacyCookieRestoreCheck(static function (array $attrs): bool {
            return ($attrs['_fs_test_portal_only'] ?? '') !== '';
        });

        $session = $manager->getSymfonySession();
        $session->set('_fs_test_portal_only', '1');

        $this->assertFalse($manager->isLoggedIn());
    }
}
