<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security tests for SQL injection prevention patterns in the codebase.
 * 
 * Tests verify that:
 * 1. Models use var2str for all user input
 * 2. Query builders use proper escaping
 * 3. Common injection patterns would be neutralized
 */
class SqlInjectionPreventionTest extends TestCase
{
    public function testOidcModelsUseVar2Str(): void
    {
        $modelFiles = glob(FS_FOLDER . '/plugins/OidcProvider/model/*.php');

        if (empty($modelFiles)) {
            $this->markTestSkipped('OIDC model files not found');
        }

        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            if (strpos($content, 'extends fs_model') !== false) {
                $hasSaveMethod = strpos($content, 'function save') !== false;

                if ($hasSaveMethod) {
                    $this->assertStringContainsString(
                        'var2str',
                        $content,
                        "Model $basename with save() should use var2str for SQL safety"
                    );
                }
            }
        }
    }

    public function testRefreshTokenModelUsesVar2StrInSave(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_refresh_token.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_refresh_token.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str($this->token)', $content);
        $this->assertStringContainsString('var2str($this->nick)', $content);
        $this->assertStringContainsString('var2str($this->client_id)', $content);
        $this->assertStringContainsString('var2str($this->family)', $content);
    }

    public function testAccessTokenModelUsesVar2StrInSave(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_access_token.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_access_token.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str($this->token)', $content);
        $this->assertStringContainsString('var2str($this->nick)', $content);
        $this->assertStringContainsString('var2str($this->client_id)', $content);
    }

    public function testAuthCodeModelUsesVar2StrInSave(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_auth_code.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_auth_code.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str', $content);
    }

    public function testClientModelUsesVar2StrInSave(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_client.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_client.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str($this->client_id)', $content);
        $this->assertStringContainsString('var2str($this->name)', $content);
    }

    public function testNoDirectStringConcatenationInQueries(): void
    {
        $modelFiles = glob(FS_FOLDER . '/plugins/OidcProvider/model/*.php');

        if (empty($modelFiles)) {
            $this->markTestSkipped('OIDC model files not found');
        }

        $dangerousPatterns = [
            '/WHERE\s+\w+\s*=\s*[\'"]?\s*\.\s*\$_/',
            '/WHERE\s+\w+\s*=\s*[\'"]?\s*\.\s*\$this->(?!var2str)/',
        ];

        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            foreach ($dangerousPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $content,
                    "Model $basename may have SQL injection vulnerability"
                );
            }
        }
    }

    public function testRateLimiterUsesVar2Str(): void
    {
        $limiterPath = FS_FOLDER . '/plugins/OidcProvider/Service/RateLimiter.php';

        if (!file_exists($limiterPath)) {
            $this->markTestSkipped('RateLimiter.php not found');
        }

        $content = file_get_contents($limiterPath);

        $this->assertStringContainsString('var2str', $content);
    }

    public function testUserProfileModelUsesVar2Str(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_user_profile.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_user_profile.php not found');
        }

        $content = file_get_contents($modelPath);

        if (strpos($content, 'function save') !== false) {
            $this->assertStringContainsString('var2str', $content);
        } else {
            $this->markTestSkipped('oidc_user_profile.php does not define a save() method in the current implementation');
        }
    }

    public function testConsentModelUsesVar2Str(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_consent.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_consent.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str', $content);
    }

    public function testAuditLogModelUsesVar2Str(): void
    {
        $modelPath = FS_FOLDER . '/plugins/OidcProvider/model/oidc_audit_log.php';

        if (!file_exists($modelPath)) {
            $this->markTestSkipped('oidc_audit_log.php not found');
        }

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('var2str', $content);
    }

    public function testNoRawUserInputInSelectStatements(): void
    {
        $serviceFiles = glob(FS_FOLDER . '/plugins/OidcProvider/Service/*.php');

        if (empty($serviceFiles)) {
            $this->markTestSkipped('OIDC service files not found');
        }

        $dangerousPatterns = [
            '/->select\([^)]*\$_GET/',
            '/->select\([^)]*\$_POST/',
            '/->select\([^)]*\$_REQUEST/',
        ];

        foreach ($serviceFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            foreach ($dangerousPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $content,
                    "Service $basename may use unsanitized input in SELECT"
                );
            }
        }
    }
}
