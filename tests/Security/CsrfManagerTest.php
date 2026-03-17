<?php

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
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