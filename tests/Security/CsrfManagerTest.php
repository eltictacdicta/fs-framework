<?php

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Tests\Controller\Concerns\ExtractsMethodBody;

class CsrfManagerTest extends TestCase
{
    use ExtractsMethodBody;

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

    // =========================================================================
    // STH-05 / SC-01 — the ensureSession() fallback is no longer a bare
    // session_start().
    //
    // That branch is only reachable when SessionManager is unavailable, so it
    // cannot consume SessionManager::sessionCookieParams(). It sets the SESSION
    // NAME from the same dependency-free resolver the legacy bootstrap uses
    // (base/fs_session_identity.php), which is the invariant SC-01 states: no
    // default-named session outside the installer exception. The default name
    // is what emits a PHPSESSID cookie and re-opens the dual-cookie regression.
    //
    // The Set-Cookie header itself is not observable here (CLI keeps no header
    // list); session_name() after the start is the input-seam assertion, and it
    // can only hold if the name was set BEFORE session_start().
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheFallbackSessionStartUsesTheSharedSessionName(): void
    {
        $_SESSION = [];
        $_COOKIE = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        CsrfManager::startSharedNativeSession();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame(SessionManager::resolveSessionName(), session_name());
        $this->assertNotSame('PHPSESSID', session_name());
    }

    public function testTheFallbackStartSetsTheNameBeforeStarting(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Security/CsrfManager.php');
        $body = $this->methodBody($source, 'startSharedNativeSession');

        $nameAt = strpos($body, 'session_name(');
        $startAt = strpos($body, 'session_start(');

        $this->assertNotFalse($nameAt, 'The fallback must set the shared session name.');
        $this->assertNotFalse($startAt);
        $this->assertLessThan($startAt, $nameAt, 'The name must be set BEFORE session_start().');

        $ensureBody = $this->methodBody($source, 'ensureSession');
        $this->assertStringContainsString('startSharedNativeSession(', $ensureBody);
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