<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Controller\Concerns\ExtractsMethodBody;

final class SecurityHeadersTest extends TestCase
{
    use ExtractsMethodBody;

    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        putenv('FS_HSTS_MAX_AGE');
        putenv('FS_HSTS_INCLUDE_SUBDOMAINS');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('FS_HSTS_MAX_AGE');
        putenv('FS_HSTS_INCLUDE_SUBDOMAINS');

        parent::tearDown();
    }

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
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("connect-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net", $policy);
    }

    public function testNonceIsStableWithinTheSameRequest(): void
    {
        $first = SecurityHeaders::nonce();
        $second = SecurityHeaders::nonce();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }

    public function testAdditionalFormActionSourcesAreAppendedToDefaultPolicy(): void
    {
        if ((defined('FS_DISABLE_CSP') && FS_DISABLE_CSP) || defined('FS_CSP_POLICY') || (defined('FS_CSP_FORM_ACTION') && trim((string) FS_CSP_FORM_ACTION) !== '')) {
            $this->markTestSkipped('Form-action policy overridden by local configuration.');
        }

        $policy = SecurityHeaders::contentSecurityPolicy(['https://buffet3d.ddev.site']);

        $this->assertStringContainsString("form-action 'self' https://buffet3d.ddev.site", $policy);
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

    // =========================================================================
    // STH-04 — Strict-Transport-Security
    //
    // The header itself is not observable under the CLI SAPI (header() is
    // accepted, headers_list() stays empty), so these assert the DECISION: the
    // value the application would send. The live header is the operator-owned
    // probe O.4, never a passing test.
    // =========================================================================

    public function testHstsValueIsTheDocumentedDefaultOnASecureRequest(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $value = SecurityHeaders::hstsHeaderValue();

        $this->assertSame('max-age=31536000', $value);
        $this->assertStringNotContainsString('includeSubDomains', $value, 'includeSubDomains is opt-in (design D6).');
    }

    public function testHstsValueIsEmptyOnAnInsecureRequest(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertSame('', SecurityHeaders::hstsHeaderValue());
    }

    #[RunInSeparateProcess]
    public function testTheDocumentedEscapeHatchDisablesHsts(): void
    {
        $_SERVER['HTTPS'] = 'on';
        putenv('FS_HSTS_MAX_AGE=0');

        $this->assertSame('', SecurityHeaders::hstsHeaderValue(), 'FS_HSTS_MAX_AGE=0 must disable emission entirely.');
    }

    #[RunInSeparateProcess]
    public function testIncludeSubDomainsIsOptIn(): void
    {
        $_SERVER['HTTPS'] = 'on';
        putenv('FS_HSTS_INCLUDE_SUBDOMAINS=1');

        $this->assertSame('max-age=31536000; includeSubDomains', SecurityHeaders::hstsHeaderValue());
    }

    /**
     * STH-04 scope statement: the API entry points get a transport-only helper,
     * never the full browser-facing header set.
     */
    public function testApplyTransportHeadersEmitsTheTransportHeaderOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Security/SecurityHeaders.php');
        $body = $this->methodBody($source, 'applyTransportHeaders');

        $this->assertStringContainsString('applyHstsHeader(', $body);
        $this->assertStringNotContainsString('applyDefaultHeaders(', $body);
        $this->assertStringNotContainsString('contentSecurityPolicy(', $body);
        $this->assertStringNotContainsString('X-Content-Type-Options', $body);
        $this->assertStringNotContainsString('X-Frame-Options', $body);
    }

    /**
     * STH-04 coverage boundary (U6.7): the two non-browser entry points emit the
     * transport header and nothing else.
     */
    public function testTheApiAndCronEntryPointsEmitTheTransportHeaders(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['api.php', 'cron.php'] as $entryPoint) {
            $source = (string) file_get_contents($root . '/' . $entryPoint);

            $this->assertStringContainsString(
                'applyTransportHeaders()',
                $source,
                $entryPoint . ' must emit HSTS through the transport-only helper.'
            );
            $this->assertStringNotContainsString(
                'applyDefaultHeaders()',
                $source,
                $entryPoint . ' must not gain CSP, nosniff or X-Frame-Options.'
            );
        }
    }
}
