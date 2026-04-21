<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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

declare(strict_types=1);

namespace FSFramework\Plugins\legacy_support;

use Throwable;

final class LegacyCompatibility
{
    public static function verifyAndUpgradeLegacyPassword(
        object $user,
        string $plainPassword,
        ?string $legacySalt = null
    ): bool
    {
        if (!isset($user->password) || !is_string($user->password)) {
            return false;
        }

        if (!self::verifyLegacyPassword($user->password, $plainPassword, $legacySalt)) {
            return false;
        }

        self::reportDeprecatedComponent(
            'legacy.auth',
            'legacy_password_fallback',
            'migrar usuarios legacy a hashes Argon2ID'
        );

        if (!is_callable([$user, 'set_password']) || !is_callable([$user, 'save'])) {
            // No se puede migrar, pero la contraseña legacy es válida
            return true;
        }

        // Solo intentar migrar si la contraseña cumple requisitos de longitud
        // Si no cumple, el login sigue siendo válido pero no se migra
        if (mb_strlen($plainPassword) < 8 || mb_strlen($plainPassword) > 32) {
            return true;
        }

        try {
            if ($user->set_password($plainPassword) === false) {
                // Fallo en migración, pero la contraseña legacy es válida
                return true;
            }

            if ($user->save() === false) {
                $userId = self::getOpaqueUserId($user);
                error_log(sprintf(
                    'FSFramework legacy_support: failed to persist migrated legacy password for user %s after successful rehash.',
                    $userId
                ));
            }

            return true;
        } catch (Throwable $e) {
            $userId = self::getOpaqueUserId($user);
            error_log(sprintf(
                'FSFramework legacy_support: exception migrating legacy password for user %s: %s',
                $userId,
                $e->getMessage()
            ));
            return true;
        }
    }

    private static function getOpaqueUserId(object $user): string
    {
        $identifier = $user->nick ?? $user->id ?? 'unknown';

        return hash('sha256', (string) $identifier);
    }

    public static function verifyLegacyPassword(
        string $storedHash,
        string $plainPassword,
        ?string $legacySalt = null
    ): bool
    {
        if ($legacySalt !== null && hash_equals($storedHash, sha1($legacySalt . $plainPassword))) {
            return true;
        }

        return hash_equals($storedHash, sha1($plainPassword))
            || hash_equals($storedHash, sha1(mb_strtolower($plainPassword, 'UTF8')))
            || hash_equals($storedHash, md5($plainPassword));
    }

    public static function reportDeprecatedComponent(
        string $component,
        string $method,
        ?string $replacement = null
    ): void {
        LegacyUsageTracker::incrementLegacyComponent($component, $method);

        $message = sprintf('%s() está deprecado y será retirado en v3.0.', $method);
        if (!empty($replacement)) {
            $message .= sprintf(' Migración recomendada: %s.', $replacement);
        }

        @trigger_error($message, E_USER_DEPRECATED);
    }
}
