<?php
/**
 * Tests para PasswordHasherService — hash y verificación de contraseñas.
 */

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use FSFramework\Security\PasswordHasherService;

class PasswordHasherServiceTest extends TestCase
{
    private PasswordHasherService $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasherService();
    }

    // =====================================================================
    // Hash & Verify
    // =====================================================================

    public function testHashReturnsNonEmptyString(): void
    {
        $hash = $this->hasher->hash('mi_password');
        $this->assertNotEmpty($hash);
        $this->assertNotSame('mi_password', $hash);
    }

    public function testHashProducesDifferentHashesForSamePassword(): void
    {
        $hash1 = $this->hasher->hash('password123');
        $hash2 = $this->hasher->hash('password123');
        // bcrypt genera salt aleatorio, los hashes deben ser diferentes
        $this->assertNotSame($hash1, $hash2);
    }

    public function testVerifyCorrectPassword(): void
    {
        $hash = $this->hasher->hash('correct_password');
        $this->assertTrue($this->hasher->verify($hash, 'correct_password'));
    }

    public function testVerifyWrongPassword(): void
    {
        $hash = $this->hasher->hash('correct_password');
        $this->assertFalse($this->hasher->verify($hash, 'wrong_password'));
    }

    // =====================================================================
    // isModernHash()
    // =====================================================================

    public function testIsModernHashDetectsBcrypt(): void
    {
        $hash = $this->hasher->hash('test');
        $this->assertTrue($this->hasher->isModernHash($hash));
    }

    public function testIsModernHashRejectsLegacySha1(): void
    {
        $legacyHash = sha1('salt' . 'password');
        $this->assertFalse($this->hasher->isModernHash($legacyHash));
    }

    public function testIsModernHashRejectsLegacyMd5(): void
    {
        $legacyHash = md5('password');
        $this->assertFalse($this->hasher->isModernHash($legacyHash));
    }

    // =====================================================================
    // Legacy support
    // =====================================================================

    public function testVerifyWithLegacySha1(): void
    {
        $salt = 'test_salt';
        $password = 'legacy_password';
        $legacyHash = sha1($salt . $password);

        $result = $this->hasher->verifyWithLegacySupport($legacyHash, $password, $salt);
        $this->assertTrue($result);
    }

    public function testVerifyWithLegacySha1WrongPassword(): void
    {
        $salt = 'test_salt';
        $legacyHash = sha1($salt . 'correct');

        $result = $this->hasher->verifyWithLegacySupport($legacyHash, 'wrong', $salt);
        $this->assertFalse($result);
    }

    public function testVerifyAndMigrateUpdatesHash(): void
    {
        $salt = 'my_salt';
        $password = 'my_password';
        $legacyHash = sha1($salt . $password);
        $storedHash = $legacyHash;

        $migrated = false;
        $result = $this->hasher->verifyAndMigrate(
            $storedHash,
            $password,
            $salt,
            function ($newHash) use (&$migrated) {
                $migrated = true;
            }
        );

        $this->assertTrue($result);
        $this->assertTrue($migrated);
        // El hash almacenado debe haber cambiado a formato moderno
        $this->assertNotSame($legacyHash, $storedHash);
        $this->assertTrue($this->hasher->isModernHash($storedHash));
    }

    // =====================================================================
    // getHashInfo()
    // =====================================================================

    public function testGetHashInfoBcrypt(): void
    {
        $hash = $this->hasher->hash('test');
        $info = $this->hasher->getHashInfo($hash);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('algoName', $info);
    }

    public function testGetHashInfoLegacy(): void
    {
        $hash = sha1('test');
        $info = $this->hasher->getHashInfo($hash);

        $this->assertIsArray($info);
    }

    // =====================================================================
    // generateSalt()
    // =====================================================================

    public function testGenerateSaltLength(): void
    {
        $salt = $this->hasher->generateSalt(16);
        // generateSalt(16) => bin2hex(random_bytes(8)) => 16 hex chars
        $this->assertSame(16, strlen($salt));
    }

    public function testGenerateSaltUnique(): void
    {
        $salt1 = $this->hasher->generateSalt();
        $salt2 = $this->hasher->generateSalt();
        $this->assertNotSame($salt1, $salt2);
    }
}
