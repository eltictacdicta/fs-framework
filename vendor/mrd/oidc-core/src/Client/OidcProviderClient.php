<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Client;

use OneLogin\OidcCore\Config\OidcProviderConfiguration;
use OneLogin\OidcCore\Discovery\OidcEndpointResolver;
use OneLogin\OidcCore\Exception\OidcAuthenticationException;
use OneLogin\OidcCore\Exception\OidcTransportException;
use OneLogin\OidcCore\Jwks\JwksDocument;
use OneLogin\OidcCore\Token\TokenSet;
use OneLogin\OidcCore\Token\TokenRequestBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @api */
final class OidcProviderClient
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $jwks = null;

    private readonly OidcEndpointResolver $endpointResolver;

    private readonly TokenRequestBuilder $tokenRequestBuilder;

    private readonly OidcProviderConfiguration $configuration;

    public function __construct(
        string $issuer,
        string $clientId,
        string $clientSecret = '',
        string $scope = 'openid profile email',
        bool $verifyPeer = true,
        float $timeout = 15.0,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
        $this->configuration = OidcProviderConfiguration::fromArray([
            'issuer' => $issuer,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope,
            'verify_peer' => $verifyPeer,
            'timeout' => $timeout,
        ]);
        $this->endpointResolver = new OidcEndpointResolver($this->configuration->issuer());
        $this->tokenRequestBuilder = new TokenRequestBuilder(
            $this->configuration->clientId(),
            $this->configuration->clientSecret(),
            $this->configuration->scope()
        );
    }

    public static function fromConfiguration(
        OidcProviderConfiguration $configuration,
        ?HttpClientInterface $httpClient = null
    ): self {
        return new self(
            $configuration->issuer(),
            $configuration->clientId(),
            $configuration->clientSecret(),
            $configuration->scope(),
            $configuration->verifyPeer(),
            $configuration->timeout(),
            $httpClient
        );
    }

    public static function fromTokenEndpoint(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret = '',
        string $scope = 'openid profile email',
        bool $verifyPeer = true,
        float $timeout = 15.0,
        ?HttpClientInterface $httpClient = null
    ): self {
        return self::fromConfiguration(
            OidcProviderConfiguration::fromTokenEndpoint($tokenEndpoint, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => $scope,
                'verify_peer' => $verifyPeer,
                'timeout' => $timeout,
            ]),
            $httpClient
        );
    }

    public function configuration(): OidcProviderConfiguration
    {
        return $this->configuration;
    }

    public function endpointResolver(): OidcEndpointResolver
    {
        return $this->endpointResolver;
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier = null): TokenSet
    {
        return $this->requestTokens(
            $this->tokenRequestBuilder->buildAuthorizationCodePayload($code, $redirectUri, $codeVerifier)
        );
    }

    public function requestPasswordTokens(string $username, string $password): TokenSet
    {
        return $this->requestTokens($this->tokenRequestBuilder->buildPasswordPayload($username, $password));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchUserInfo(string $accessToken): ?array
    {
        try {
            $response = $this->client()->request('GET', $this->endpointResolver->userinfoEndpoint(), [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'timeout' => $this->configuration->timeout(),
            ]);

            $statusCode = $response->getStatusCode();
            $payload = json_decode($response->getContent(false), true);

            return $statusCode === 200 && is_array($payload) ? $payload : null;
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchJwks(): ?array
    {
        if ($this->jwks === null) {
            try {
                $response = $this->client()->request('GET', $this->endpointResolver->jwksUri(), [
                    'timeout' => $this->configuration->timeout(),
                ]);

                if ($response->getStatusCode() === 200) {
                    $this->jwks = JwksDocument::decodeKeys($response->getContent(false));
                }
            } catch (TransportExceptionInterface) {
                return null;
            }
        }

        return $this->jwks;
    }

    /**
     * @param array<string, string> $payload
     */
    private function requestTokens(array $payload): TokenSet
    {
        try {
            $response = $this->client()->request('POST', $this->endpointResolver->tokenEndpoint(), [
                'body' => $payload,
                'timeout' => $this->configuration->timeout(),
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $payload = json_decode($body, true);
            $isArrayPayload = is_array($payload);

            if ($statusCode !== 200 || !$isArrayPayload || isset($payload['error'])) {
                $message = 'unknown';
                if ($isArrayPayload) {
                    $errorDescription = $payload['error_description'] ?? null;
                    $error = $payload['error'] ?? null;
                    if (is_string($errorDescription) && $errorDescription !== '') {
                        $message = $errorDescription;
                    } elseif (is_string($error) && $error !== '') {
                        $message = $error;
                    }
                }

                throw new OidcAuthenticationException($message);
            }

            return TokenSet::fromArray($payload);
        } catch (TransportExceptionInterface $exception) {
            throw new OidcTransportException($exception->getMessage(), 0, $exception);
        }
    }

    private function client(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create([
            'verify_peer' => $this->configuration->verifyPeer(),
            'verify_host' => $this->configuration->verifyPeer(),
        ]);
    }
}
