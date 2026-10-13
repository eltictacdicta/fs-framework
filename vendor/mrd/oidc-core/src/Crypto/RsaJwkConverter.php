<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Crypto;

/** @api */
final class RsaJwkConverter
{
    /**
     * @param array<string, mixed> $jwk
     */
    public static function toPublicKeyPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $modulus = self::base64UrlDecode((string) $jwk['n']);
        $exponent = self::base64UrlDecode((string) $jwk['e']);
        if ($modulus === false || $exponent === false) {
            return null;
        }

        $modulus = ltrim($modulus, "\x00");
        if ($modulus === '') {
            $modulus = "\x00";
        } elseif (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        $exponent = ltrim($exponent, "\x00");
        if ($exponent === '') {
            $exponent = "\x00";
        } elseif (ord($exponent[0]) > 0x7f) {
            $exponent = "\x00" . $exponent;
        }

        $rsaKey = self::asn1Length(0x30, self::asn1Length(0x02, $modulus) . self::asn1Length(0x02, $exponent));
        $oid = pack('H*', '300d06092a864886f70d0101010500');
        $der = self::asn1Length(0x30, $oid . self::asn1Length(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function asn1Length(int $tag, string $data): string
    {
        $length = strlen($data);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $data;
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $data;
    }
}
