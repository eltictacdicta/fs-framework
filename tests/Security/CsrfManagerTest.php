<?php

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

class CsrfManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionManager::reset();
        $this->resetCsrfState();
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $this->resetCsrfState();
        SessionManager::reset();
        parent::tearDown();
    }

    public function testGenerateTokenIgnoresMissingUploadedTempFiles(): void
    {
        $_FILES['fplugin'] = [
            'name' => 'broken.zip',
            'type' => 'application/zip',
            'tmp_name' => '/tmp/phpTf36Wr',
            'error' => UPLOAD_ERR_OK,
            'size' => 123,
        ];

        $token = CsrfManager::generateToken();

        $this->assertNotSame('', $token);
    }

    public function testRefreshAndRemoveTokenLifecycle(): void
    {
        $initial = CsrfManager::generateToken('custom_form');
        $refreshed = CsrfManager::refreshToken('custom_form');

        $this->assertNotSame('', $initial);
        $this->assertNotSame($initial, $refreshed);
        $this->assertTrue(CsrfManager::isValid($refreshed, 'custom_form'));
        $removed = CsrfManager::removeToken('custom_form');
        $this->assertNotNull($removed);
        $this->assertFalse(CsrfManager::isValid($refreshed, 'custom_form'));
    }

    public function testValidateRequestAcceptsPostFieldAndHeaderToken(): void
    {
        $token = CsrfManager::generateToken('request_form');

        $postRequest = new Request([], [CsrfManager::FIELD_NAME => $token]);
        $legacyPostRequest = new Request([], ['_token' => $token]);
        $headerRequest = new Request();
        $headerRequest->headers->set(CsrfManager::HEADER_NAME, $token);

        $this->assertTrue(CsrfManager::validateRequest($postRequest, 'request_form'));
        $this->assertTrue(CsrfManager::validateRequest($legacyPostRequest, 'request_form'));
        $this->assertTrue(CsrfManager::validateRequest($headerRequest, 'request_form'));
        $this->assertFalse(CsrfManager::validateRequest(new Request(), 'request_form'));
    }

    public function testFieldRendersModernAndLegacyTokenNames(): void
    {
        $field = CsrfManager::field('rendered_form');

        $this->assertStringContainsString('name="' . CsrfManager::FIELD_NAME . '"', $field);
        $this->assertStringContainsString('name="_token"', $field);
    }

    public function testGenerateTokenReusesSessionManagerSessionWhenAvailable(): void
    {
        $manager = SessionManager::getInstance();
        $sharedSession = $manager->getSymfonySession();

        $token = CsrfManager::generateToken('shared_form');

        $this->assertNotSame('', $token);

        $ref = new ReflectionClass(CsrfManager::class);
        $property = $ref->getProperty('session');
        $property->setAccessible(true);

        $this->assertSame($sharedSession, $property->getValue());
    }

    public function testDuplicateTokenReuseIsDetected(): void
    {
        $token = CsrfManager::generateToken('duplicate_form');

        // First validation should succeed
        $this->assertTrue(CsrfManager::isValidWithReuseCheck($token, 'duplicate_form', true));

        // Second validation with same token should fail (reused)
        $this->assertFalse(CsrfManager::isValidWithReuseCheck($token, 'duplicate_form', true));
    }

    public function testIsValidWithoutReuseCheckAllowsReuse(): void
    {
        $token = CsrfManager::generateToken('reuse_allowed_form');

        $this->assertTrue(CsrfManager::isValid($token, 'reuse_allowed_form'));
        $this->assertTrue(CsrfManager::isValid($token, 'reuse_allowed_form'));
    }

    public function testReuseCheckCanBeDisabled(): void
    {
        $token = CsrfManager::generateToken('soft_reuse_form');

        // With reuse prevention disabled, same token works multiple times
        $this->assertTrue(CsrfManager::isValidWithReuseCheck($token, 'soft_reuse_form', false));
        $this->assertTrue(CsrfManager::isValidWithReuseCheck($token, 'soft_reuse_form', false));
    }

    public function testInvalidTokenFailsReuseCheck(): void
    {
        $this->assertFalse(CsrfManager::isValidWithReuseCheck('invalid_token', 'invalid_form', true));
    }

    public function testIsReusedDetectsPreviouslyMarkedToken(): void
    {
        $token = CsrfManager::generateToken('marked_form');

        $this->assertFalse(CsrfManager::isReused($token, 'marked_form'));

        CsrfManager::markAsUsed($token, 'marked_form');

        $this->assertTrue(CsrfManager::isReused($token, 'marked_form'));
    }

    public function testTokenPresenceGuardRefreshesWhenAbsent(): void
    {
        // Simula sesión sin token CSRF
        $_SESSION = [];
        SessionManager::reset();

        // Limpia la caché estática de CsrfManager
        $this->resetCsrfState();

        // Fuerza tokenVerified a false para que la guarda se ejecute
        $ref = new ReflectionClass(CsrfManager::class);
        $prop = $ref->getProperty('tokenVerified');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        // Al llamar a getManager() el guard debe crear el token
        $manager = CsrfManager::getManager();
        $token = $manager->getToken(CsrfManager::DEFAULT_TOKEN_ID);

        $this->assertNotSame('', $token->getValue(), 'El valor del token no debe estar vacío');

        // Verifica que la bandera tokenVerified quedó activada
        $this->assertTrue($prop->getValue(), 'tokenVerified debe ser true después de la guarda');
    }

    public function testTokenPresenceGuardCachesPerRequest(): void
    {
        // Primera llamada activa el guard
        $first = CsrfManager::getManager();

        $ref = new ReflectionClass(CsrfManager::class);
        $prop = $ref->getProperty('tokenVerified');
        $prop->setAccessible(true);

        $this->assertTrue($prop->getValue(), 'tokenVerified debe ser true después de la primera llamada');

        // Segunda llamada devuelve la misma instancia sin re-chequear
        $second = CsrfManager::getManager();
        $this->assertSame($first, $second, 'Segunda llamada debe devolver el mismo manager');
    }

    private function resetCsrfState(): void
    {
        $ref = new ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        $prop = $ref->getProperty('tokenVerified');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }
}