<?php

declare(strict_types=1);

namespace Tests\Service;

use FSFramework\Plugins\OidcProvider\Service\PublicFormSecretDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicFormSecretDecoderTest extends TestCase
{
    public function testReturnsPlainPasswordWhenTransportIsMissing(): void
    {
        $decoder = new PublicFormSecretDecoder();
        $request = Request::create('/oauth/login', 'POST', ['password' => 'S3cret!']);

        self::assertSame('S3cret!', $decoder->decodePasswordFromRequest($request));
    }

    public function testDecodesBase64Password(): void
    {
        $decoder = new PublicFormSecretDecoder();
        $plain = 'Nueva!2026';
        $request = Request::create('/oauth/login', 'POST', [
            '_pwd_transport' => 'b64',
            'password' => base64_encode($plain),
        ]);

        self::assertSame($plain, $decoder->decodePasswordFromRequest($request));
    }

    public function testDecodeLoginCredentialPrefersNeutralFieldName(): void
    {
        $decoder = new PublicFormSecretDecoder();
        $plain = 'Secret123';
        $request = Request::create('/oauth/login', 'POST', [
            '_pwd_transport' => 'b64',
            'credential' => base64_encode($plain),
        ]);

        self::assertSame($plain, $decoder->decodeLoginCredential($request));
    }

    public function testReturnsEmptyStringForInvalidBase64Payload(): void
    {
        $decoder = new PublicFormSecretDecoder();
        $request = Request::create('/oauth/login', 'POST', [
            '_pwd_transport' => 'b64',
            'password' => '%%%not-base64%%%',
        ]);

        self::assertSame('', $decoder->decodePasswordFromRequest($request));
    }
}
