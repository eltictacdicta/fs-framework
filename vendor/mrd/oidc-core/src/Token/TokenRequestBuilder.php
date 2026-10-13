<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Token;

/** @api */
final class TokenRequestBuilder
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret = '',
        private readonly string $scope = 'openid profile email'
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function buildAuthorizationCodePayload(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null) {
            $payload['code_verifier'] = $codeVerifier;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    public function buildPasswordPayload(string $username, string $password): array
    {
        return [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $username,
            'password' => $password,
            'scope' => $this->scope,
        ];
    }
}
