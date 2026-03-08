<?php

namespace Tests\Api;

use FSFramework\Api\Auth\ChainedAuthAdapter;
use FSFramework\Api\Auth\Contract\ApiAuthInterface;
use PHPUnit\Framework\TestCase;

class ChainedAuthAdapterTest extends TestCase
{
    private function createMockAdapter(array $overrides = []): ApiAuthInterface
    {
        $mock = $this->createMock(ApiAuthInterface::class);

        foreach ($overrides as $method => $returnValue) {
            $mock->method($method)->willReturn($returnValue);
        }

        return $mock;
    }

    public function testConstructorRequiresAtLeastOneAdapter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChainedAuthAdapter([]);
    }

    public function testValidateTokenTriesPrimaryFirst(): void
    {
        $primary = $this->createMockAdapter([
            'validateToken' => ['success' => true, 'user' => ['nick' => 'admin', 'auth_method' => 'opaque']],
        ]);
        $secondary = $this->createMockAdapter([
            'validateToken' => ['success' => true, 'user' => ['nick' => 'admin', 'auth_method' => 'oidc']],
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->validateToken('some-token');

        $this->assertTrue($result['success']);
        $this->assertEquals('opaque', $result['user']['auth_method']);
    }

    public function testValidateTokenFallsBackToSecondary(): void
    {
        $primary = $this->createMockAdapter([
            'validateToken' => ['success' => false, 'error' => 'Invalid'],
        ]);
        $secondary = $this->createMockAdapter([
            'validateToken' => ['success' => true, 'user' => ['nick' => 'admin', 'auth_method' => 'oidc']],
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->validateToken('oidc-token');

        $this->assertTrue($result['success']);
        $this->assertEquals('oidc', $result['user']['auth_method']);
    }

    public function testValidateTokenReturnsErrorWhenAllFail(): void
    {
        $primary = $this->createMockAdapter([
            'validateToken' => ['success' => false, 'error' => 'Invalid primary'],
        ]);
        $secondary = $this->createMockAdapter([
            'validateToken' => ['success' => false, 'error' => 'Invalid secondary'],
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->validateToken('bad-token');

        $this->assertFalse($result['success']);
    }

    public function testLogoutTriesAllAdapters(): void
    {
        $primary = $this->createMockAdapter([
            'logout' => ['success' => false, 'error' => 'Token not found'],
        ]);
        $secondary = $this->createMockAdapter([
            'logout' => ['success' => true, 'message' => 'OIDC token revoked'],
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->logout('oidc-token');

        $this->assertTrue($result['success']);
        $this->assertEquals('OIDC token revoked', $result['message']);
    }

    public function testAuthenticateDelegatesToPrimary(): void
    {
        $primary = $this->createMockAdapter([
            'authenticate' => ['success' => true, 'token' => 'abc123'],
        ]);
        $secondary = $this->createMockAdapter();

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->authenticate('admin', 'password');

        $this->assertTrue($result['success']);
        $this->assertEquals('abc123', $result['token']);
    }

    public function testRevokeUserTokensRevokesInAllAdapters(): void
    {
        $primary = $this->createMockAdapter([
            'revokeUserTokens' => true,
        ]);
        $secondary = $this->createMockAdapter([
            'revokeUserTokens' => true,
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $this->assertTrue($chain->revokeUserTokens('admin'));
    }

    public function testRevokeUserTokensReturnsTrueIfAnySucceeds(): void
    {
        $primary = $this->createMockAdapter([
            'revokeUserTokens' => false,
        ]);
        $secondary = $this->createMockAdapter([
            'revokeUserTokens' => true,
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $this->assertTrue($chain->revokeUserTokens('admin'));
    }

    public function testRefreshTokensFallsBackToSecondary(): void
    {
        $primary = $this->createMockAdapter([
            'refreshTokens' => ['success' => false, 'error' => 'Not supported'],
        ]);
        $secondary = $this->createMockAdapter([
            'refreshTokens' => ['success' => true, 'token' => 'new-token'],
        ]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $result = $chain->refreshTokens('some-refresh');

        $this->assertTrue($result['success']);
        $this->assertEquals('new-token', $result['token']);
    }

    public function testIsAdminDelegatesToPrimary(): void
    {
        $primary = $this->createMockAdapter(['isAdmin' => true]);
        $secondary = $this->createMockAdapter(['isAdmin' => false]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $this->assertTrue($chain->isAdmin());
    }

    public function testHasAccessToDelegatesToPrimary(): void
    {
        $primary = $this->createMockAdapter(['hasAccessTo' => true]);
        $secondary = $this->createMockAdapter(['hasAccessTo' => false]);

        $chain = new ChainedAuthAdapter([$primary, $secondary]);
        $this->assertTrue($chain->hasAccessTo('admin_users'));
    }

    public function testGetCurrentUserDelegatesToPrimary(): void
    {
        $primary = $this->createMockAdapter(['getCurrentUser' => null]);
        $chain = new ChainedAuthAdapter([$primary]);
        $this->assertNull($chain->getCurrentUser());
    }

    public function testGetCurrentTokenDelegatesToPrimary(): void
    {
        $primary = $this->createMockAdapter(['getCurrentToken' => 'token-abc']);
        $chain = new ChainedAuthAdapter([$primary]);
        $this->assertEquals('token-abc', $chain->getCurrentToken());
    }
}
