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

/**
 * Equipos con sesión iniciada.
 *
 * Cada usuario ve y cierra los suyos. Un administrador ve los de todos, o los
 * de uno con `snick`, y puede cerrarlos.
 */
#[\FSFramework\Attribute\SelfService]
class user_sessions extends fs_controller
{
    /** @var list<array<string, mixed>> */
    public $devices = [];

    /** Usuario cuyos equipos se muestran; '' = todos (solo administrador). */
    public $snick = '';

    /** Hash del token de este equipo, para marcarlo en la lista. */
    public $current_hash = '';

    public $max_age_days = 30;

    public $idle_days = 7;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Sesiones', 'admin', FALSE, FALSE);
    }

    protected function private_core()
    {
        $requested = trim((string) $this->request->query->get('snick', ''));
        // Un no administrador solo puede ver lo suyo, pida lo que pida.
        $this->snick = $this->user->admin ? $requested : $this->user->nick;
        $this->max_age_days = intdiv(RememberMeTokens::maxAge(), 86400);
        $this->idle_days = intdiv(\FSFramework\Security\SessionPolicy::getRememberMeCookieLifetime(), 86400);

        $sessionToken = (string) (\FSFramework\Security\SessionManager::getInstance()->get('user_logkey') ?? '');
        $this->current_hash = $sessionToken === '' ? '' : RememberMeTokens::hashOf($sessionToken);

        if ($this->request->isMethod('POST') && $this->isCsrfValid()) {
            $this->handle_action();
        }

        $this->devices = array_map(fn(array $d) => $d + [
            'device' => self::device_name($d['user_agent']),
            'current' => $d['token_hash'] === $this->current_hash,
        ], RememberMeTokens::active($this->snick === '' ? null : $this->snick));
    }

    private function handle_action(): void
    {
        $nick = trim((string) $this->request->request->get('nick', ''));
        if ($nick === '' || (!$this->user->admin && $nick !== $this->user->nick)) {
            $this->new_error_msg('No puedes cerrar sesiones de otro usuario.');
            return;
        }

        switch ((string) $this->request->request->get('action', '')) {
            case 'revoke':
                $hash = (string) $this->request->request->get('hash', '');
                if (!RememberMeTokens::revokeByHash($nick, $hash)) {
                    $this->new_error_msg('No se pudo cerrar la sesión en ese equipo. Inténtalo de nuevo.');
                    return;
                }
                if ($nick === $this->user->nick && $hash === $this->current_hash) {
                    $this->logout_here();
                }
                $this->new_message('Sesión cerrada en ese equipo.', TRUE, 'login');
                return;

            case 'revoke_all':
                if (!RememberMeTokens::revokeAll($nick)) {
                    $this->new_error_msg('No se pudieron cerrar las sesiones. Inténtalo de nuevo.');
                    return;
                }
                if ($nick === $this->user->nick) {
                    $this->logout_here();
                }
                $this->new_message('Sesión cerrada en todos los equipos de ' . $nick . '.', TRUE, 'login', TRUE);
                return;
        }
    }

    /** Cerrar la sesión de este equipo: el logout normal (cookies y sesión) y al inicio. */
    private function logout_here(): void
    {
        header('Location: ' . $this->logoutUrl());
        exit();
    }

    /** «Chrome en Windows» a partir del User-Agent; lo justo para reconocer el equipo. */
    public static function device_name(string $userAgent): string
    {
        $browsers = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];
        $systems = ['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows',
            'Mac OS X' => 'Mac', 'Linux' => 'Linux'];
        $pick = static function (array $map) use ($userAgent): string {
            foreach ($map as $needle => $name) {
                if (str_contains($userAgent, $needle)) {
                    return $name;
                }
            }
            return '';
        };

        $browser = $pick($browsers);
        $system = $pick($systems);
        if ($browser === '' && $system === '') {
            return $userAgent === '' ? 'Equipo desconocido' : mb_substr($userAgent, 0, 40);
        }

        return trim(($browser ?: 'Navegador') . ($system !== '' ? ' en ' . $system : ''));
    }
}
