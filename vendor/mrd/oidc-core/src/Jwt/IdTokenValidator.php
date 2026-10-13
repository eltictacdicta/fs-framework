<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Jwt;

use OneLogin\OidcCore\Crypto\RsaJwkConverter;

/** @api */
final class IdTokenValidator
{
    private const CLOCK_SKEW_LEEWAY = 30;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $logPrefix = 'OIDC'
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $jwks
     * @param string $expectedTyp Expected JWT "typ" header for this validation
     *                            context. Defaults to "JWT" so existing
     *                            ID-token callers keep their strict behaviour.
     *                            Pass "logout+jwt" for back-channel logout
     *                            tokens. An absent "typ" is always accepted.
     * @return array<string, mixed>
     */
    public function validate(string $idToken, array $jwks, ?string $expectedNonce = null, string $expectedTyp = 'JWT'): array
    {
        $payload = [];
        $tokenData = $this->parseJwtForValidation($idToken);

        if ($tokenData !== null) {
            $publicKey = $this->findValidationKey($jwks, $tokenData['kid']);
            if ($publicKey === null) {
                $this->log('No matching key in JWKS for kid=' . $tokenData['kid']);
            } else {
                $pem = RsaJwkConverter::toPublicKeyPem($publicKey);
                if ($pem === null) {
                    $this->log('Failed to convert JWK to PEM');
                } elseif ($this->verifySignature($tokenData['signature_input'], $tokenData['signature'], $pem)
                    && $this->validateHeader($tokenData['header'], $expectedTyp)
                    && $this->validateClaims($tokenData['payload'])
                    && $this->validateNonce($tokenData['payload'], $expectedNonce)) {
                    $payload = $tokenData['payload'];
                }
            }
        }

        return $payload;
    }

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signature_input: string, signature: string, kid: string}|null
     */
    private function parseJwtForValidation(string $idToken): ?array
    {
        $tokenData = null;
        $parts = explode('.', $idToken);
        if (count($parts) === 3) {
            $headerJson = Jwt::base64UrlDecode($parts[0]);
            $payloadJson = Jwt::base64UrlDecode($parts[1]);
            $signature = Jwt::base64UrlDecode($parts[2]);

            if ($headerJson !== false && $payloadJson !== false && $signature !== false) {
                $header = json_decode($headerJson, true);
                $payload = json_decode($payloadJson, true);

                if (is_array($header) && is_array($payload) && ($header['alg'] ?? '') === 'RS256') {
                    $tokenData = [
                        'header' => $header,
                        'payload' => $payload,
                        'signature_input' => $parts[0] . '.' . $parts[1],
                        'signature' => $signature,
                        'kid' => (string) ($header['kid'] ?? ''),
                    ];
                }
            }
        }

        return $tokenData;
    }

    /**
     * @param array<int, array<string, mixed>> $jwks
     * @return array<string, mixed>|null
     */
    private function findValidationKey(array $jwks, string $kid): ?array
    {
        foreach ($jwks as $key) {
            if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
                return $key;
            }
        }

        if ($kid === '') {
            foreach ($jwks as $key) {
                if (($key['kty'] ?? '') === 'RSA' && ($key['use'] ?? 'sig') === 'sig') {
                    return $key;
                }
            }
        }

        return null;
    }

    private function verifySignature(string $signatureInput, string $signature, string $pem): bool
    {
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            $this->log('Invalid public key PEM');
            return false;
        }

        if (openssl_verify($signatureInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            $this->log('id_token signature verification failed');
            return false;
        }

        return true;
    }

    /**
     * The "typ" header parameter is optional. When present it must describe a
     * JWT of the type expected for the validation context: the ID-token path
     * expects "JWT" (the default), while the back-channel logout path must
     * request "logout+jwt" per OpenID Connect Back-Channel Logout 1.0 section
     * 2.4. Comparison is case-insensitive: RFC 7519 sections 5.1 and 7.3
     * exempt "typ" (and "cty") from the JWT case-sensitive string comparison
     * rules. An absent "typ" is accepted in every context, preserving the
     * pre-existing ID-token behaviour and the optional nature of the header.
     *
     * @param array<string, mixed> $header
     */
    private function validateHeader(array $header, string $expectedTyp = 'JWT'): bool
    {
        if (!array_key_exists('typ', $header)) {
            return true;
        }

        $type = $header['typ'];
        if (!is_string($type) || strcasecmp($type, $expectedTyp) !== 0) {
            $this->log('token header typ is not ' . $expectedTyp . ': ' . var_export($type, true));
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateClaims(array $payload): bool
    {
        $isValid = true;

        foreach (['iss', 'aud', 'exp'] as $claim) {
            if (!array_key_exists($claim, $payload)) {
                $this->log('id_token missing required claim: ' . $claim);
                $isValid = false;
            }
        }

        if ($isValid && !is_numeric($payload['exp'])) {
            $this->log('id_token claim exp is not numeric: ' . var_export($payload['exp'], true));
            $isValid = false;
        }

        if ($isValid && array_key_exists('nbf', $payload) && !is_numeric($payload['nbf'])) {
            $this->log('id_token claim nbf is not numeric: ' . var_export($payload['nbf'], true));
            $isValid = false;
        }

        if ($isValid && array_key_exists('iat', $payload) && !is_numeric($payload['iat'])) {
            $this->log('id_token claim iat is not numeric: ' . var_export($payload['iat'], true));
            $isValid = false;
        }

        if ($isValid && rtrim((string) $payload['iss'], '/') !== rtrim($this->issuer, '/')) {
            $this->log('id_token issuer mismatch: ' . (string) $payload['iss']);
            $isValid = false;
        }

        if ($isValid) {
            $audience = is_array($payload['aud']) ? array_values($payload['aud']) : [$payload['aud']];
            if (!in_array($this->clientId, $audience, true)) {
                $this->log('id_token audience mismatch');
                $isValid = false;
            } elseif (count($audience) > 1 && !$this->validateAuthorizedParty($payload['azp'] ?? null)) {
                $isValid = false;
            }
        }

        if ($isValid && (int) $payload['exp'] < (time() - self::CLOCK_SKEW_LEEWAY)) {
            $this->log('id_token has expired');
            $isValid = false;
        }

        if ($isValid && array_key_exists('nbf', $payload)
            && (int) $payload['nbf'] > (time() + self::CLOCK_SKEW_LEEWAY)) {
            $this->log('id_token is not valid yet (nbf is in the future)');
            $isValid = false;
        }

        if ($isValid && array_key_exists('iat', $payload)
            && (int) $payload['iat'] > (time() + self::CLOCK_SKEW_LEEWAY)) {
            $this->log('id_token iat is in the future');
            $isValid = false;
        }

        return $isValid;
    }

    /**
     * When the audience claim lists more than one recipient, OIDC Core requires
     * the azp claim to be present and to match the client this library was
     * configured for. Single-audience tokens keep the previous behaviour and
     * do not require azp.
     */
    private function validateAuthorizedParty(mixed $authorizedParty): bool
    {
        if ($authorizedParty === null) {
            $this->log('id_token has multiple audiences but is missing the azp claim');
            return false;
        }

        if (!is_string($authorizedParty) || $authorizedParty !== $this->clientId) {
            $this->log('id_token azp mismatch: ' . var_export($authorizedParty, true));
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateNonce(array $payload, ?string $expectedNonce): bool
    {
        if ($expectedNonce === null) {
            return true;
        }

        $tokenNonce = $payload['nonce'] ?? null;
        if ($tokenNonce === null) {
            $this->log('id_token missing nonce claim but nonce was expected');
            return false;
        }

        if (!hash_equals($expectedNonce, (string) $tokenNonce)) {
            $this->log('id_token nonce mismatch');
            return false;
        }

        return true;
    }

    private function log(string $message): void
    {
        error_log($this->logPrefix . ': ' . $message);
    }
}
