<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Security;

/**
 * Almacén de tokens de sesión, uno por equipo. Usar {@see RememberMeTokens}.
 *
 * `$keyRef` ata cada token a la contraseña vigente del usuario: si cambia, el
 * token deja de valer. Solo se guarda el sha256 de cada token.
 */
interface RememberMeTokenStore
{
    /** Crea un token para un equipo nuevo. Devuelve '' si no se pudo guardar. */
    public function issue(string $nick, string $keyRef, string $userAgent, string $ip): string;

    public function isValid(string $nick, string $keyRef, string $token): bool;

    /**
     * Para sesiones ya abiertas, sin cargar el usuario: ¿sigue el token sin
     * revocar ni caducar, con el usuario activo y con la contraseña vigente?
     */
    public function isLive(string $nick, string $token): bool;

    /** Anula un equipo por el hash de su token. FALSE si el almacén falló. */
    public function revoke(string $nick, string $tokenHash): bool;

    /** Anula todos los equipos del usuario. FALSE si el almacén falló. */
    public function revokeAll(string $nick): bool;

    /**
     * Equipos vigentes (mismas reglas que isLive), el último usado primero; de un usuario o, con null, de todos.
     *
     * @return list<array{token_hash: string, nick: string, user_agent: string, ip: string, created_at: int, last_used: int}>
     */
    public function active(?string $nick): array;
}
