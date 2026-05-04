<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testDefaultPolicyIncludesExpectedBaselineDirectives(): void
    {
        if ((defined('FS_DISABLE_CSP') && FS_DISABLE_CSP) || defined('FS_CSP_POLICY')) {
            $this->markTestSkipped('Default CSP overridden by local configuration.');
        }

        $policy = SecurityHeaders::contentSecurityPolicy();

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString('https://cdnjs.cloudflare.com', $policy);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $policy);
        $this->assertStringContainsString('https://fonts.googleapis.com', $policy);
        $this->assertStringContainsString("form-action 'self' https://github.com", $policy);
    }

    public function testNonceIsStableWithinTheSameRequest(): void
    {
        $first = SecurityHeaders::nonce();
        $second = SecurityHeaders::nonce();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }

    public function testNonceAttributeContainsEscapedNonce(): void
    {
        $nonce = SecurityHeaders::nonce();

        $this->assertSame(
            'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"',
            SecurityHeaders::nonceAttribute()
        );
    }

    #[RunInSeparateProcess]
    public function testConfiguredPolicyOverrideWins(): void
    {
        if (defined('FS_CSP_POLICY')) {
            $this->assertSame(trim((string) FS_CSP_POLICY), SecurityHeaders::contentSecurityPolicy());
            return;
        }

        define('FS_CSP_POLICY', "default-src 'self'; script-src 'self'");

        $this->assertSame("default-src 'self'; script-src 'self'", SecurityHeaders::contentSecurityPolicy());
    }
}
