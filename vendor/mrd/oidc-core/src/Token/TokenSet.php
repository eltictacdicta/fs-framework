<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Token;

/** @api */
final class TokenSet
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private readonly array $payload)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function accessToken(): string
    {
        return (string) ($this->payload['access_token'] ?? '');
    }

    public function refreshToken(): string
    {
        return (string) ($this->payload['refresh_token'] ?? '');
    }

    public function idToken(): string
    {
        return (string) ($this->payload['id_token'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}

