<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Jwt;

/**
 * Lightweight JWT payload decoding helpers.
 *
 * This API only decodes the token structure and payload. It does not verify
 * signatures, issuer, audience, expiry, or any other security property, so it
 * must not be used on its own for authentication or authorization decisions.
 *
 * @api
 */
final class Jwt
{
    /**
     * Decode the payload section of a JWT without verifying its signature.
     *
     * @return array<string, mixed>
     */
    public static function decodePayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = self::base64UrlDecode($parts[1]);
        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
