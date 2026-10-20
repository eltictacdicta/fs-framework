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

declare(strict_types=1);

/**
 * Autorización de scripts de operador (cron.php, install helpers, scripts de
 * emergencia) cuando se invocan por HTTP.
 *
 * El guard original confiaba en `REMOTE_ADDR` loopback. Detrás de un reverse
 * proxy esa dirección es la del proxy, así que TODOS los clientes parecen
 * locales y el script quedaba público: en el caso de cron.php eso permitía
 * disparar los crons de los plugins, el self-heal de tablas y las migraciones.
 *
 * Reglas:
 *  - CLI (cron del sistema, operador con shell) siempre autorizado.
 *  - Si el script pasa un token configurado, hace falta ese token (header
 *    `X-Cron-Token` / `X-Operator-Token` o parámetro `token`).
 *  - Sin token configurado se acepta sólo un llamador realmente local: loopback
 *    y sin cabeceras de forwarding. Un proxy siempre agrega alguna.
 *
 * La función es pura (recibe `$_SERVER` y `$_REQUEST`) para poder testearla sin
 * ejecutar el script que la usa.
 */
function fs_operator_authorize(string $sapi, array $server, array $params, ?string $configuredToken): bool
{
    if ($sapi === 'cli') {
        return true;
    }

    $token = trim((string) $configuredToken);

    if ($token !== '') {
        $provided = '';

        foreach (['HTTP_X_OPERATOR_TOKEN', 'HTTP_X_CRON_TOKEN', 'HTTP_X_CRON_SECRET'] as $header) {
            if (!empty($server[$header]) && is_string($server[$header])) {
                $provided = trim($server[$header]);
                break;
            }
        }

        if ($provided === '') {
            foreach (['token', 'cron_token'] as $param) {
                if (!empty($params[$param]) && is_string($params[$param])) {
                    $provided = trim($params[$param]);
                    break;
                }
            }
        }

        return $provided !== '' && hash_equals($token, $provided);
    }

    $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
    if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
        return false;
    }

    foreach ([
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_FORWARDED',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
    ] as $header) {
        if (!empty($server[$header])) {
            return false;
        }
    }

    return true;
}
