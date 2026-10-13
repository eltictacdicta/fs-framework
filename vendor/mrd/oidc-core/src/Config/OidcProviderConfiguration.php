<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Config;

use OneLogin\OidcCore\Discovery\OidcEndpointResolver;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @api */
final class OidcProviderConfiguration
{
    private function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope,
        private readonly bool $verifyPeer,
        private readonly float $timeout,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $resolver = new OptionsResolver();
        $resolver->define('issuer')->required()->allowedTypes('string')->allowedValues(static fn (string $value): bool => trim($value) !== '');
        $resolver->define('client_id')->required()->allowedTypes('string')->allowedValues(static fn (string $value): bool => trim($value) !== '');
        $resolver->define('client_secret')->default('')->allowedTypes('string');
        $resolver->define('scope')->default('openid profile email')->allowedTypes('string');
        $resolver->define('verify_peer')->default(true)->allowedTypes('bool');
        $resolver->define('timeout')->default(15.0)->allowedTypes('float', 'int')->normalize(static fn (Options $options, mixed $value): float => (float) $value);

        /** @var array{issuer: string, client_id: string, client_secret: string, scope: string, verify_peer: bool, timeout: float} $resolved */
        $resolved = $resolver->resolve($options);

        return new self(
            rtrim($resolved['issuer'], '/'),
            $resolved['client_id'],
            $resolved['client_secret'],
            $resolved['scope'],
            $resolved['verify_peer'],
            $resolved['timeout'],
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromTokenEndpoint(string $tokenEndpoint, array $options): self
    {
        return self::fromArray([
            'issuer' => OidcEndpointResolver::fromTokenEndpoint($tokenEndpoint)->issuer(),
            'client_id' => $options['client_id'] ?? '',
            'client_secret' => $options['client_secret'] ?? '',
            'scope' => $options['scope'] ?? 'openid profile email',
            'verify_peer' => $options['verify_peer'] ?? true,
            'timeout' => $options['timeout'] ?? 15.0,
        ]);
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function verifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }
}
