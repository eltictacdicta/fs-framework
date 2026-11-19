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

use FSFramework\Security\RememberMeTokens;
use FSFramework\Security\RememberMeTokenStore;
use FSFramework\Security\SessionPolicy;

/**
 * Tokens de sesión por equipo (tabla fs_user_tokens). Solo se guarda el sha256
 * de cada token. Usar la API de {@see RememberMeTokens}, no este modelo.
 */
class fs_user_token extends fs_model implements RememberMeTokenStore
{
    /** No reescribir last_used en cada petición: basta con una vez por hora. */
    private const TOUCH_EVERY = 3600;

    public function __construct()
    {
        parent::__construct('fs_user_tokens');
    }

    public function issue(string $nick, string $keyRef, string $userAgent, string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();

        // Lo caducado se limpia al emitir: es poco frecuente y no hace falta un cron.
        $this->db->exec('DELETE FROM fs_user_tokens WHERE ' . self::expiredSql($now) . ';');

        $ok = $this->db->exec('INSERT INTO fs_user_tokens (token_hash, nick, key_ref, user_agent, ip, created_at, last_used) VALUES ('
            . $this->var2str(RememberMeTokens::hashOf($token)) . ', ' . $this->var2str($nick) . ', ' . $this->var2str($keyRef) . ', '
            . $this->var2str($userAgent) . ', ' . $this->var2str($ip) . ', ' . $now . ', ' . $now . ');');

        return $ok ? $token : '';
    }

    public function isValid(string $nick, string $keyRef, string $token): bool
    {
        $tokenHash = RememberMeTokens::hashOf($token);
        $now = time();
        $rows = $this->db->select('SELECT key_ref, last_used FROM fs_user_tokens WHERE token_hash = '
            . $this->var2str($tokenHash) . ' AND nick = ' . $this->var2str($nick) . ' AND NOT (' . self::expiredSql($now) . ');');
        if (!$rows || !hash_equals((string) $rows[0]['key_ref'], $keyRef)) {
            return false;
        }

        if ($now - (int) $rows[0]['last_used'] >= self::TOUCH_EVERY) {
            $this->db->exec('UPDATE fs_user_tokens SET last_used = ' . $now . ' WHERE token_hash = ' . $this->var2str($tokenHash) . ';');
        }

        return true;
    }

    public function isLive(string $nick, string $token): bool
    {
        return $this->liveRows('t.token_hash = ' . $this->var2str(RememberMeTokens::hashOf($token))
            . ' AND t.nick = ' . $this->var2str($nick)) !== [];
    }

    public function revoke(string $nick, string $tokenHash): bool
    {
        return (bool) $this->db->exec('DELETE FROM fs_user_tokens WHERE token_hash = ' . $this->var2str($tokenHash)
            . ' AND nick = ' . $this->var2str($nick) . ';');
    }

    public function revokeAll(string $nick): bool
    {
        return (bool) $this->db->exec('DELETE FROM fs_user_tokens WHERE nick = ' . $this->var2str($nick) . ';');
    }

    public function active(?string $nick): array
    {
        $rows = $this->liveRows($nick === null ? '1 = 1' : 't.nick = ' . $this->var2str($nick));

        return array_map(static fn($r) => [
            'token_hash' => (string) $r['token_hash'],
            'nick' => (string) $r['nick'],
            'user_agent' => (string) ($r['user_agent'] ?? ''),
            'ip' => (string) ($r['ip'] ?? ''),
            'created_at' => (int) $r['created_at'],
            'last_used' => (int) $r['last_used'],
        ], $rows);
    }

    /**
     * Tokens sin caducar de usuarios activos, atados a su contraseña vigente.
     * Esto no depende de que el borrado al cambiar la contraseña o desactivar
     * haya funcionado: la fila puede quedar, pero ya no cuenta.
     *
     * @return list<array<string, mixed>>
     */
    private function liveRows(string $where): array
    {
        $rows = $this->db->select('SELECT t.token_hash, t.nick, t.key_ref, t.user_agent, t.ip, t.created_at, t.last_used, u.password'
            . ' FROM fs_user_tokens t JOIN fs_users u ON u.nick = t.nick'
            . ' WHERE ' . $where . ' AND u.enabled = TRUE'
            . ' AND NOT (' . self::expiredSql(time(), 't.') . ') ORDER BY t.last_used DESC;') ?: [];

        return array_values(array_filter($rows, static fn($r) => hash_equals(
            (string) $r['key_ref'],
            RememberMeTokens::keyFor((object) ['password' => $r['password']])
        )));
    }

    /** Caducado: sin uso durante la vida de la cookie, o más viejo que el máximo absoluto. */
    private static function expiredSql(int $now, string $alias = ''): string
    {
        return $alias . 'last_used < ' . ($now - SessionPolicy::getRememberMeCookieLifetime())
            . ' OR ' . $alias . 'created_at < ' . ($now - RememberMeTokens::maxAge());
    }

    public function exists()
    {
        return FALSE;
    }

    public function save()
    {
        return FALSE;
    }

    public function delete()
    {
        return FALSE;
    }
}
