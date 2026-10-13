<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Discovery;

/** @api */
final class OidcEndpointResolver
{
    public function __construct(private readonly string $issuer)
    {
    }

    public static function fromTokenEndpoint(string $tokenEndpoint): self
    {
        return new self(rtrim(str_replace('/oauth/token', '', $tokenEndpoint), '/'));
    }

    public function issuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    public function endpoint(string $path): string
    {
        return $this->issuer() . '/' . ltrim($path, '/');
    }

    public function tokenEndpoint(): string
    {
        return $this->endpoint('/oauth/token');
    }

    public function userinfoEndpoint(): string
    {
        return $this->endpoint('/oauth/userinfo');
    }

    public function jwksUri(): string
    {
        return $this->endpoint('/.well-known/jwks.json');
    }
}
