<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Identity;

use OneLogin\OidcCore\Client\OidcProviderClient;
use OneLogin\OidcCore\Exception\OidcIdentityException;
use OneLogin\OidcCore\Jwt\IdTokenValidator;
use OneLogin\OidcCore\Token\TokenSet;

/** @api */
final class OidcIdentityResolver
{
    public function __construct(
        private readonly OidcProviderClient $providerClient,
        private readonly string $logPrefix = 'OIDC'
    ) {
    }

    public function resolveIdentity(TokenSet $tokenSet): AuthenticatedIdentity
    {
        $idToken = $tokenSet->idToken();
        if ($idToken === '') {
            throw new OidcIdentityException('Authentication response missing required id_token');
        }

        $jwks = $this->providerClient->fetchJwks();
        if ($jwks === null) {
            throw new OidcIdentityException('Could not fetch JWKS for id_token validation');
        }

        $configuration = $this->providerClient->configuration();
        $claims = (new IdTokenValidator(
            $configuration->issuer(),
            $configuration->clientId(),
            $this->logPrefix
        ))->validate($idToken, $jwks);

        if ($claims === []) {
            throw new OidcIdentityException('ID token validation failed');
        }

        $accessToken = $tokenSet->accessToken();
        if ($accessToken !== '') {
            $userinfo = $this->providerClient->fetchUserInfo($accessToken);
            if (is_array($userinfo)) {
                $claims += $userinfo;
            }
        }

        return AuthenticatedIdentity::fromClaims($claims);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveClaims(TokenSet $tokenSet): array
    {
        return $this->resolveIdentity($tokenSet)->claims();
    }
}
