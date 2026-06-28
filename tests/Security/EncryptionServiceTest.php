<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\EncryptionService;
use FSFramework\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression tests for {@see EncryptionService}.
 *
 * The bug this guards against: an active JWK persisted in `oidc_jwks` was
 * encrypted with a previous `FS_SECRET_KEY` constant value, while the
 * current one is different. {@see EncryptionService::decrypt()} must throw
 * a {@see \RuntimeException} instead of returning garbage so the caller
 * (KeyManager) can detect the mismatch and rotate the key.
 */
final class EncryptionServiceTest extends TestCase
{
    private const TEST_SECRET = 'encryption-service-test-secret-key-32+chars';
    private const ALTERNATE_SECRET = 'alternate-secret-key-also-32-chars-xxxxxxxxxxx';

    private ?string $originalSecret = null;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(SecretManager::class);
        $property = $reflection->getProperty('secret');
        $property->setAccessible(true);

        $this->originalSecret = $property->getValue();
        $property->setValue(null, self::TEST_SECRET);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(SecretManager::class);
        $property = $reflection->getProperty('secret');
        $property->setAccessible(true);
        $property->setValue(null, $this->originalSecret);

        parent::tearDown();
    }

    public function testEncryptProducesVersionedPayload(): void
    {
        $service = new EncryptionService();
        $payload = $service->encrypt('hello world');

        $this->assertStringStartsWith('v1:', $payload);
    }

    public function testRoundtripShortString(): void
    {
        $service = new EncryptionService();

        $this->assertSame('hi', $service->decrypt($service->encrypt('hi')));
    }

    public function testRoundtripEmptyString(): void
    {
        $service = new EncryptionService();

        $this->assertSame('', $service->decrypt($service->encrypt('')));
    }

    public function testRoundtripLongPayloadTypicalRsaPrivateKey(): void
    {
        $service = new EncryptionService();

        // 4096-bit RSA private key in PEM format is ~3.2KB. We approximate with
        // a larger fixture than a typical symmetric secret to make sure
        // sodium's secretbox handles multi-chunk payloads without losing data.
        $fixture = $this->buildRsaLikePem();

        $this->assertSame($fixture, $service->decrypt($service->encrypt($fixture)));
    }

    public function testRoundtripUtf8MultibyteString(): void
    {
        $service = new EncryptionService();
        $fixture = 'café — naïve façade — 漢字 — emoji 🔐';

        $this->assertSame($fixture, $service->decrypt($service->encrypt($fixture)));
    }

    public function testDecryptGarbagePayloadThrows(): void
    {
        $service = new EncryptionService();

        $this->expectException(\RuntimeException::class);
        $service->decrypt('not-a-valid-payload');
    }

    public function testDecryptUnsupportedVersionThrows(): void
    {
        $service = new EncryptionService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Formato de cifrado no soportado');
        $service->decrypt('v0:Zm9vYmFy');
    }

    public function testDecryptWithDifferentKeyThrows(): void
    {
        // Encrypted under the current secret.
        $service = new EncryptionService();
        $cipherText = $service->encrypt('legacy-private-key-pem');

        // Simulate that the application's FS_SECRET_KEY was rotated since the
        // payload was persisted: swap the in-memory secret.
        $reflection = new ReflectionClass(SecretManager::class);
        $property = $reflection->getProperty('secret');
        $property->setAccessible(true);
        $property->setValue(null, self::ALTERNATE_SECRET);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se pudo descifrar el payload');
        $service->decrypt($cipherText);
    }

    public function testEncryptProducesDifferentCiphertextForSamePlaintext(): void
    {
        // sodium_crypto_secretbox uses a random nonce per call, so two
        // encryptions of the same plaintext must differ.
        $service = new EncryptionService();

        $this->assertNotSame(
            $service->encrypt('repeatable'),
            $service->encrypt('repeatable')
        );
    }

    private function buildRsaLikePem(): string
    {
        $header = "-----BEGIN PRIVATE KEY-----\n";
        $footer = "\n-----END PRIVATE KEY-----\n";
        $body = '';
        for ($i = 0, $line = ''; $i < 3200; $i++) {
            $line .= chr(65 + ($i % 26));
            if (strlen($line) === 64) {
                $body .= $line . "\n";
                $line = '';
            }
        }
        if ($line !== '') {
            $body .= $line . "\n";
        }
        return $header . $body . $footer;
    }
}
