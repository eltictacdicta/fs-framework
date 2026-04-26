<?php

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

class CsrfManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetCsrfState();
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $this->resetCsrfState();
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

    private function resetCsrfState(): void
    {
        $ref = new ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}