<?php
/**
 * Tests para fs_auth en rutas internas de validación de contraseña.
 */

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_auth.php';

class FsAuthTest extends TestCase
{
    public function testIsPasswordValidMigratesLegacySha1UsingExistingFlow(): void
    {
        $user = new class() {
            public string $password;
            public int $setPasswordCalls = 0;
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->password = sha1('Secret123');
            }

            public function set_password($password): bool
            {
                $this->setPasswordCalls++;
                $this->password = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                return true;
            }

            public function save(): bool
            {
                $this->saveCalls++;
                return true;
            }
        };

        $result = $this->invokeIsPasswordValid($user, 'Secret123');

        $this->assertTrue($result);
        $this->assertSame(1, $user->setPasswordCalls);
        $this->assertSame(1, $user->saveCalls);
        $this->assertStringStartsWith('$argon2id$', $user->password);
    }

    public function testIsPasswordValidRejectsLowercasedLegacySha1Bypass(): void
    {
        $user = new class() {
            public string $password;
            public int $setPasswordCalls = 0;
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->password = sha1('secret123');
            }

            public function set_password($password): bool
            {
                $this->setPasswordCalls++;
                $this->password = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
                return true;
            }

            public function save(): bool
            {
                $this->saveCalls++;
                return true;
            }
        };

        $result = $this->invokeIsPasswordValid($user, 'Secret123');

        $this->assertFalse($result);
        $this->assertSame(0, $user->setPasswordCalls);
        $this->assertSame(0, $user->saveCalls);
        $this->assertSame(sha1('secret123'), $user->password);
    }

    private function invokeIsPasswordValid(object $user, string $password): bool
    {
        $method = new \ReflectionMethod('fs_auth', 'isPasswordValid');
        $method->setAccessible(true);

        return $method->invoke(null, $user, $password);
    }
}
