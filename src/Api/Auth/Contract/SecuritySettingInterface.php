<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Api\Auth\Contract;

/**
 * Interface para manejar las configuraciones de seguridad de la API
 *
 * @author FacturaScripts Team
 */
interface SecuritySettingInterface
{
    // Constantes de claves de configuración
    public const SETTING_MAX_LOGIN_ATTEMPTS = 'max_login_attempts';
    public const SETTING_LOCKOUT_TIME = 'lockout_time';
    public const SETTING_TOKEN_EXPIRATION = 'default_token_expiration';
    public const SETTING_REFRESH_TOKEN_EXPIRATION = 'default_refresh_token_expiration';
    public const SETTING_ENABLE_RATE_LIMITING = 'enable_rate_limiting';
    public const SETTING_ENABLE_SECURITY_EVENTS = 'enable_security_events';
    public const SETTING_MAX_SECURITY_EVENTS_PER_DAY = 'max_security_events_per_day';

    /**
     * Busca una configuración por su clave
     */
    public function getByKey(string $setting_key): self|false;

    /**
     * Obtiene el valor de una configuración específica
     */
    public function getValue(string $setting_key, mixed $default = null): mixed;

    /**
     * Establece el valor de una configuración
     */
    public function setValue(string $setting_key, mixed $value, string $type = 'string', ?string $description = null, string $creado_por = 'system'): bool;

    /**
     * Obtiene todas las configuraciones como array asociativo
     * @return array<string, mixed>
     */
    public function getAllAsArray(): array;

    /**
     * Resetea todas las configuraciones a valores por defecto
     */
    public function resetToDefaults(): bool;

    /**
     * Obtiene todas las configuraciones
     * @return static[]
     */
    public function all(): array;
}
