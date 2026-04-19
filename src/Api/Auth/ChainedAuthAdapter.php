<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Api\Auth;

use FSFramework\Api\Auth\Contract\ApiAuthInterface;
use FSFramework\model\fs_user;

/**
 * Generic chain-of-responsibility adapter for token validation.
 *
 * Tries each registered ApiAuthInterface adapter in order until one
 * succeeds. This allows the core API layer to stay generic while plugins
 * such as api_base and OidcProvider supply concrete token strategies.
 */
class ChainedAuthAdapter implements ApiAuthInterface
{
    /** @var ApiAuthInterface[] */
    private array $adapters;

    /** @var ApiAuthInterface Primary adapter used for non-validation operations */
    private ApiAuthInterface $primary;

    /**
     * @param ApiAuthInterface[] $adapters Ordered list of adapters to try
     */
    public function __construct(array $adapters)
    {
        if (empty($adapters)) {
            throw new \InvalidArgumentException('At least one adapter is required');
        }

        $this->adapters = $adapters;
        $this->primary = $adapters[0];
    }

    public function authenticate(string $nick, string $password): array
    {
        return $this->primary->authenticate($nick, $password);
    }

    public function logout(string $token): array
    {
        foreach ($this->adapters as $adapter) {
            $result = $adapter->logout($token);
            if ($result['success']) {
                return $result;
            }
        }
        return ['success' => false, 'error' => 'Token not found in any adapter'];
    }

    public function validateToken(string $token): array
    {
        foreach ($this->adapters as $adapter) {
            $result = $adapter->validateToken($token);
            if ($result['success']) {
                return $result;
            }
        }
        return ['success' => false, 'error' => 'Token inválido'];
    }

    public function refreshTokens(string $refreshToken): array
    {
        foreach ($this->adapters as $adapter) {
            $result = $adapter->refreshTokens($refreshToken);
            if ($result['success']) {
                return $result;
            }
        }
        return ['success' => false, 'error' => 'Refresh token inválido'];
    }

    public function isAdmin(): bool
    {
        return $this->primary->isAdmin();
    }

    public function hasAccessTo(string $pageName): bool
    {
        return $this->primary->hasAccessTo($pageName);
    }

    public function getCurrentUser(): ?fs_user
    {
        return $this->primary->getCurrentUser();
    }

    public function getCurrentToken(): ?string
    {
        return $this->primary->getCurrentToken();
    }

    public function revokeUserTokens(string $nick): bool
    {
        $success = false;
        foreach ($this->adapters as $adapter) {
            if ($adapter->revokeUserTokens($nick)) {
                $success = true;
            }
        }
        return $success;
    }

}
