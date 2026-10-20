<?php

declare(strict_types=1);

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

use FSFramework\Cache\CacheManager;
use FSFramework\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;

/**
 * The nick+IP counter alone allows online brute force against one account by
 * rotating the source address. The account-level counter bounds that case.
 */
final class LoginThrottleAccountTest extends TestCase
{
    private string $originalRemoteAddr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRemoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        CacheManager::reset();
        LoginThrottle::clear('victim');
    }

    protected function tearDown(): void
    {
        LoginThrottle::clear('victim');
        CacheManager::reset();

        if ($this->originalRemoteAddr === '') {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddr;
        }

        parent::tearDown();
    }

    public function testRotatingTheIpStillThrottlesTheAccount(): void
    {
        for ($i = 1; $i <= LoginThrottle::ACCOUNT_MAX_ATTEMPTS; $i++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
            LoginThrottle::recordFailure('victim');
        }

        $this->assertSame(LoginThrottle::ACCOUNT_MAX_ATTEMPTS, LoginThrottle::getAccountAttemptCount('victim'));
        $this->assertTrue(LoginThrottle::isThrottled('victim'));
    }

    public function testAccountBelowTheThresholdIsNotThrottled(): void
    {
        $attempts = LoginThrottle::ACCOUNT_MAX_ATTEMPTS - 1;

        for ($i = 1; $i <= $attempts; $i++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
            LoginThrottle::recordFailure('victim');
        }

        $this->assertSame($attempts, LoginThrottle::getAccountAttemptCount('victim'));
        $this->assertFalse(LoginThrottle::isThrottled('victim'));
    }

    /**
     * Regression: the original nick+IP counter must keep working.
     */
    public function testSameIpIsStillThrottledAtTheOriginalThreshold(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            LoginThrottle::recordFailure('victim');
        }

        $this->assertTrue(LoginThrottle::isThrottled('victim'));
    }

    public function testClearResetsBothCounters(): void
    {
        for ($i = 1; $i <= LoginThrottle::ACCOUNT_MAX_ATTEMPTS; $i++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
            LoginThrottle::recordFailure('victim');
        }

        $this->assertTrue(LoginThrottle::isThrottled('victim'));

        LoginThrottle::clear('victim');

        $this->assertSame(0, LoginThrottle::getAccountAttemptCount('victim'));
        $this->assertFalse(LoginThrottle::isThrottled('victim'));
    }
}
