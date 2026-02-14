<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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
require_once 'base/fs_ip_filter.php';

/**
 * Description of fs_login
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_login
{

    /**
     *
     * @var string
     */
    private $ban_message;

    /**
     *
     * @var fs_cache
     */
    private $cache;

    /**
     *
     * @var fs_core_log
     */
    private $core_log;

    /**
     *
     * @var fs_ip_filter
     */
    private $ip_filter;

    /**
     *
     * @var fs_user
     */
    private $user_model;

    /**
     * @var \Symfony\Component\HttpFoundation\Session\Session
     */
    private $session;

    public function __construct()
    {
        $this->ban_message = 'Tendrás que esperar ' . fs_ip_filter::BAN_SECONDS . ' segundos antes de volver a intentar entrar.';
        $this->cache = new fs_cache();
        $this->core_log = new fs_core_log();
        $this->ip_filter = new fs_ip_filter();
        $this->user_model = new fs_user();

        // Inicializar Sesión de Symfony
        // Detectar si la sesión ya fue iniciada por PHP (por ejemplo, en un plugin o configuración legacy)
        $storage = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $storage = new \Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage();
        } else {
            $storage = new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage();
        }

        try {
            $this->session = new \Symfony\Component\HttpFoundation\Session\Session($storage);
        } catch (\Exception $e) {
            // Fallback seguro en caso de error extremo
            // Si llegamos aqui y la sesion estaba activa, NativeSessionStorage fallaría
            if (session_status() === PHP_SESSION_ACTIVE) {
                $this->session = new \Symfony\Component\HttpFoundation\Session\Session(
                    new \Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage()
                );
            } else {
                $this->session = new \Symfony\Component\HttpFoundation\Session\Session();
            }
        }

        if (!$this->session->isStarted()) {
            $this->session->start();
        }
    }

    /**
     * @deprecated Usar el controlador password_reset para recuperación de contraseña por email.
     * Este método requería la contraseña de la base de datos y ha sido reemplazado
     * por un flujo seguro basado en tokens temporales enviados por email.
     */
    public function change_user_passwd()
    {
        $this->core_log->new_error('Esta función ha sido deshabilitada. Usa la opción "Olvidé mi contraseña" en la pantalla de login.');
        return FALSE;
    }

    /**
     * 
     * @param fs_user $controller_user
     *
     * @return boolean
     */
    public function log_in(&$controller_user)
    {
        $ip = fs_get_ip();
        $nick = filter_input(INPUT_POST, 'user');
        if ($this->ip_filter->is_banned($ip)) {
            $this->core_log->new_error('Tu IP ha sido baneada, ' . $nick . '. ' . $this->ban_message);
            $this->core_log->save('Tu IP ha sido baneada, ' . $nick . '. ' . $this->ban_message, 'login', TRUE);
            return FALSE;
        }

        $password = filter_input(INPUT_POST, 'password');
        if ($nick && $password) {
            if (FS_DEMO) {
                /// en el modo demo nos olvidamos de la contraseña
                return $this->log_in_demo($controller_user, $nick);
            }

            $this->ip_filter->set_attempt($ip);
            return $this->log_in_user($controller_user, $nick, $password, $ip);
        }

        if (filter_input(INPUT_COOKIE, 'user') && filter_input(INPUT_COOKIE, 'logkey')) {
            return $this->log_in_cookie($controller_user);
        }

        return FALSE;
    }

    /**
     * Gestiona el cierre de sesión
     * @param boolean $rmuser eliminar la cookie del usuario
     */
    public function log_out($rmuser = FALSE)
    {
        $path = '/';
        if (filter_input(INPUT_SERVER, 'REQUEST_URI')) {
            $aux = parse_url(str_replace('/index.php', '', filter_input(INPUT_SERVER, 'REQUEST_URI')));
            if (isset($aux['path'])) {
                $path = $aux['path'];
                if (substr($path, -1) != '/') {
                    $path .= '/';
                }
            }
        }

        // Limpiar sesión de Symfony
        $this->session->invalidate();

        /// borramos las cookies (legacy)
        if (filter_input(INPUT_COOKIE, 'logkey')) {
            setcookie('logkey', '', time() - FS_COOKIES_EXPIRE);
            setcookie('logkey', '', time() - FS_COOKIES_EXPIRE, $path);
            if ($path != '/') {
                setcookie('logkey', '', time() - FS_COOKIES_EXPIRE, '/');
            }
        }

        /// ¿Eliminamos la cookie del usuario?
        $user = filter_input(INPUT_COOKIE, 'user');
        if ($rmuser && $user) {
            setcookie('user', '', time() - FS_COOKIES_EXPIRE);
            setcookie('user', '', time() - FS_COOKIES_EXPIRE, $path);
        }

        /// guardamos el evento en el log
        $this->core_log->set_user_nick($user);
        $this->core_log->save('El usuario ha cerrado la sesión.', 'login');
    }

    /**
     * 
     * @param fs_user $controller_user
     *
     * @return bool
     */
    private function log_in_cookie(&$controller_user)
    {
        // 1. Intentar recuperar desde Sesión de Symfony (más seguro y estable)
        $nick = $this->session->get('user_nick');
        $logkey = $this->session->get('user_logkey');

        // 2. Si no hay sesión, mirar cookies (legacy / primera carga)
        if (!$nick) {
            $nick = filter_input(INPUT_COOKIE, 'user');
            $logkey = filter_input(INPUT_COOKIE, 'logkey');
        }

        if (!$nick) {
            return FALSE;
        }

        $user = $this->user_model->get($nick);
        if (!$user || !$user->enabled) {
            $this->core_log->new_error('¡El usuario ' . $nick . ' no existe o está desactivado!');
            $this->log_out(TRUE);
            $this->user_model->clean_cache(TRUE);
            $this->cache->clean();
            return $controller_user->logged_on;
        }

        if ($this->applyValidCookieLogin($user, $logkey, $controller_user)) {
            return TRUE;
        }

        if ($this->applyTrustedSessionLogin($user, $controller_user)) {
            return TRUE;
        }

        $this->handleInvalidCookieSession($user);

        return $controller_user->logged_on;
    }

    private function applyValidCookieLogin($user, $logkey, &$controller_user)
    {
        if (!hash_equals((string) ($user->log_key ?? ''), (string) ($logkey ?? ''))) {
            return false;
        }

        $user->logged_on = TRUE;
        $user->update_login();
        $this->save_session_data($user);
        $controller_user = $user;
        return true;
    }

    private function applyTrustedSessionLogin($user, &$controller_user)
    {
        if ($this->session->get('user_nick') !== $user->nick || $this->session->get('user_logged_in') !== true) {
            return false;
        }

        $controller_user = $user;
        $controller_user->logged_on = TRUE;
        $this->save_session_data($user);
        return true;
    }

    private function handleInvalidCookieSession($user)
    {
        if (is_null($user->log_key)) {
            return;
        }

        $msg = '¡Sesión no válida! ';
        if ($user->last_ip == fs_get_ip()) {
            $this->log_out(TRUE);
            return;
        }

        if (fs_is_local_ip($user->last_ip) && fs_is_local_ip(fs_get_ip())) {
            $msg .= 'Acceso detectado desde otro equipo de la red local (' . $user->last_ip . ').';
        } else {
            $msg .= 'Alguien ha accedido a esta cuenta desde otra ubicación (IP: ' . $user->last_ip . ').';
        }

        $this->core_log->new_message($msg);
        $this->log_out();
    }

    /**
     * 
     * @param fs_user $controller_user
     * @param string  $email
     *
     * @return bool
     */
    private function log_in_demo(&$controller_user, $email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->core_log->new_error('Email no válido: ' . $email);
            return FALSE;
        }

        $aux = explode('@', $email);
        $nick = substr($aux[0], 0, 12);
        if ($nick == 'admin') {
            $nick .= $this->random_string(7);
        }

        $user = $this->user_model->get($nick);
        if (!$user) {
            $user = new fs_user();
            $user->nick = $nick;
            $user->set_password('demo');
            $user->email = $email;

            /// creamos un agente para asociarlo
            $agente = new agente();
            $agente->codagente = $agente->get_new_codigo();
            $agente->nombre = $nick;
            $agente->apellidos = 'Demo';
            $agente->email = $email;

            if ($agente->save()) {
                $user->codagente = $agente->codagente;
            }
        }

        $user->new_logkey();
        if ($user->save()) {
            $this->save_session_data($user);
            $controller_user = $user;
        }

        return $controller_user->logged_on;
    }

    /**
     * 
     * @param fs_user $controller_user
     * @param string  $nick
     * @param string  $password
     * @param string  $ip
     *
     * @return boolean
     */
    private function log_in_user(&$controller_user, $nick, $password, $ip)
    {
        $user = $this->user_model->get($nick);
        if (!$user) {
            $this->core_log->new_error('El usuario o contraseña no coinciden!');
            $this->user_model->clean_cache(TRUE);
            $this->cache->clean();
            return FALSE;
        }

        if (!$user->enabled) {
            $this->core_log->new_error('El usuario ' . $user->nick . ' está desactivado, habla con tu administrador!');
            $this->core_log->save('El usuario ' . $user->nick . ' está desactivado, habla con tu administrador!', 'login', TRUE);
            $this->user_model->clean_cache(TRUE);
            $this->cache->clean();
            return FALSE;
        }

        /**
         * Comprobamos la contraseña con el método moderno password_verify
         * Para compatibilidad con versiones anteriores, también verificamos con SHA1
         */
        $password_verified = false;

        // Verificar con el método moderno (Argon2ID)
        if (password_verify($password, $user->password)) {
            $password_verified = true;
        }
        // Para compatibilidad con versiones anteriores (SHA1)
        elseif ($user->password == sha1($password) || $user->password == sha1(mb_strtolower($password, 'UTF8'))) {
            $password_verified = true;
            // Si la contraseña coincide con SHA1, actualizar el hash a Argon2ID para mayor seguridad
            $user->set_password($password);
            $user->save();
        }

        if (!$password_verified) {
            $this->core_log->new_error('¡Contraseña incorrecta! (' . $nick . ')');
            $this->core_log->save('¡Contraseña incorrecta! (' . $nick . ')', 'login', TRUE);
            return FALSE;
        }

        $user->new_logkey();

        if (!$user->admin && !$this->ip_filter->in_white_list($ip)) {
            $this->core_log->new_error('No puedes acceder desde esta IP.');
            $this->core_log->save('No puedes acceder desde esta IP.', 'login', TRUE);
        } else if ($user->save()) {
            // Guardamos en sesión Y en cookies (legacy)
            $this->save_session_data($user);

            /// añadimos el mensaje al log
            $this->core_log->save('Login correcto.', 'login');

            /// limpiamos la lista de IPs
            $this->ip_filter->clear();

            $controller_user = $user;
            return $controller_user->logged_on;
        }

        $this->core_log->new_error('Imposible guardar los datos de usuario.');
        $this->cache->clean();
        return FALSE;
    }

    /**
     * Guarda los datos de sesión en Symfony Session y Cookies (para compatibilidad)
     * @param fs_user $user
     */
    private function save_session_data($user)
    {
        // 1. Guardar en Session (robustez)
        $this->session->set('user_nick', $user->nick);
        $this->session->set('user_logkey', $user->log_key);
        $this->session->set('user_logged_in', true);

        // 2. Guardar en Cookies (compatibilidad legacy)
        // usamos el método antiguo para no romper nada que lea $_COOKIE directamente
        $this->save_cookie($user);
    }

    /**
     * 
     * @param fs_user $user
     */
    private function save_cookie($user)
    {
        setcookie('user', $user->nick, time() + FS_COOKIES_EXPIRE);
        setcookie('logkey', $user->log_key, time() + FS_COOKIES_EXPIRE);
    }

    /**
     * Devuelve un string aleatorio de longitud $length
     *
     * @param integer $length la longitud del string
     *
     * @return string la cadena aleatoria
     */
    private function random_string($length = 30)
    {
        return mb_substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
    }
}
