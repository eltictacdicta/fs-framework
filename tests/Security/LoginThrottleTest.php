<?php

namespace Tests\Security;

use FSFramework\Security\LoginThrottle;
use FSFramework\Cache\CacheManager;
use PHPUnit\Framework\TestCase;

class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CacheManager::reset();
        LoginThrottle::clear('testuser');
    }

    protected function tearDown(): void
    {
        LoginThrottle::clear('testuser');
        CacheManager::reset();
        parent::tearDown();
    }

    public function testNewUserIsNotThrottled(): void
    {
        $this->assertFalse(LoginThrottle::isThrottled('newuser'));
        $this->assertSame(0, LoginThrottle::getAttemptCount('newuser'));
    }

    public function testRecordFailureIncrementsAttemptCount(): void
    {
        LoginThrottle::recordFailure('testuser');
        $this->assertSame(1, LoginThrottle::getAttemptCount('testuser'));

        LoginThrottle::recordFailure('testuser');
        $this->assertSame(2, LoginThrottle::getAttemptCount('testuser'));
    }

    public function testClearResetsAttemptCount(): void
    {
        LoginThrottle::recordFailure('testuser');
        LoginThrottle::recordFailure('testuser');
        $this->assertSame(2, LoginThrottle::getAttemptCount('testuser'));

        LoginThrottle::clear('testuser');
        $this->assertSame(0, LoginThrottle::getAttemptCount('testuser'));
    }

    public function testUserIsThrottledAfterMaxAttempts(): void
    {
        $nick = 'throttle_test_user';
        LoginThrottle::clear($nick);

        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            LoginThrottle::recordFailure($nick);
        }

        $this->assertTrue(LoginThrottle::isThrottled($nick));
        $this->assertSame(LoginThrottle::MAX_ATTEMPTS, LoginThrottle::getAttemptCount($nick));

        LoginThrottle::clear($nick);
    }

    public function testDifferentUsersAreTrackedIndependently(): void
    {
        LoginThrottle::clear('user_a');
        LoginThrottle::clear('user_b');

        LoginThrottle::recordFailure('user_a');
        LoginThrottle::recordFailure('user_a');

        $this->assertSame(2, LoginThrottle::getAttemptCount('user_a'));
        $this->assertSame(0, LoginThrottle::getAttemptCount('user_b'));

        LoginThrottle::clear('user_a');
        LoginThrottle::clear('user_b');
    }

    public function testGetDummyHashReturnsBcryptString(): void
    {
        $hash = LoginThrottle::getDummyHash();
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);
    }

    public function testGenericErrorMessageIsDefined(): void
    {
        $this->assertNotEmpty(LoginThrottle::GENERIC_ERROR);
    }

    public function testClearOnNonexistentUserDoesNotError(): void
    {
        LoginThrottle::clear('nonexistent_user_12345');
        $this->assertFalse(LoginThrottle::isThrottled('nonexistent_user_12345'));
    }
}
