<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Identity;

use OneLogin\OidcCore\Exception\OidcIdentityException;

/** @api */
final class AuthenticatedIdentity
{
    /**
     * @param array<string, mixed> $claims
     */
    private function __construct(private readonly array $claims)
    {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(array $claims): self
    {
        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new OidcIdentityException('OIDC identity is missing required subject claim');
        }

        return new self($claims);
    }

    public function subject(): string
    {
        return (string) $this->claims['sub'];
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }
}
