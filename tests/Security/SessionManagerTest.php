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

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;

class SessionManagerTest extends TestCase
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

    public function testGetSymfonySessionReturnsUnderlyingSession(): void
    {
        $manager = SessionManager::getInstance();

        $this->assertInstanceOf(Session::class, $manager->getSymfonySession());
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
}
