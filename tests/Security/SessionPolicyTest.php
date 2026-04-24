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

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionPolicy;
use PHPUnit\Framework\TestCase;

class SessionPolicyTest extends TestCase
{
    public function testGetIdleTimeoutReturnsDefault(): void
    {
        $this->assertSame(7200, SessionPolicy::getIdleTimeout());
    }

    public function testGetAbsoluteTimeoutReturnsDefault(): void
    {
        $this->assertSame(28800, SessionPolicy::getAbsoluteTimeout());
    }

    public function testGetRememberMeCookieLifetimeReadsConstant(): void
    {
        $this->assertSame((int) FS_COOKIES_EXPIRE, SessionPolicy::getRememberMeCookieLifetime());
    }

    public function testCookieExpireForRememberMeReturnsFutureTimestamp(): void
    {
        $before = time() + SessionPolicy::getRememberMeCookieLifetime();
        $expire = SessionPolicy::cookieExpireFor(true);
        $after = time() + SessionPolicy::getRememberMeCookieLifetime();

        $this->assertGreaterThanOrEqual($before, $expire);
        $this->assertLessThanOrEqual($after, $expire);
    }

    public function testCookieExpireForSessionReturnsZero(): void
    {
        $this->assertSame(0, SessionPolicy::cookieExpireFor(false));
    }

    public function testIsExpiredReturnsFalseForFreshSession(): void
    {
        $now = time();
        $this->assertFalse(SessionPolicy::isExpired($now, $now));
    }

    public function testIsExpiredReturnsTrueWhenIdleTimeoutExceeded(): void
    {
        $now = time();
        $loginTime = $now - 100;
        $lastActivity = $now - SessionPolicy::getIdleTimeout() - 1;

        $this->assertTrue(SessionPolicy::isExpired($loginTime, $lastActivity));
    }

    public function testIsExpiredReturnsFalseWhenIdleTimeoutNotExceeded(): void
    {
        $now = time();
        $loginTime = $now - 100;
        $lastActivity = $now - SessionPolicy::getIdleTimeout() + 60;

        $this->assertFalse(SessionPolicy::isExpired($loginTime, $lastActivity));
    }

    public function testIsExpiredReturnsTrueWhenAbsoluteTimeoutExceeded(): void
    {
        $now = time();
        $loginTime = $now - SessionPolicy::getAbsoluteTimeout() - 1;
        $lastActivity = $now;

        $this->assertTrue(SessionPolicy::isExpired($loginTime, $lastActivity));
    }

    public function testIsExpiredReturnsFalseWhenAbsoluteTimeoutNotExceeded(): void
    {
        $now = time();
        $loginTime = $now - SessionPolicy::getAbsoluteTimeout() + 60;
        $lastActivity = $now;

        $this->assertFalse(SessionPolicy::isExpired($loginTime, $lastActivity));
    }

    public function testIsExpiredHandlesZeroTimestampsGracefully(): void
    {
        $this->assertTrue(SessionPolicy::isExpired(0, 0));
    }
}
