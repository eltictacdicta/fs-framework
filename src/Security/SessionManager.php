<?php
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

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * Gestor de sesiones moderno usando Symfony HttpFoundation
 *
 * Proporciona una API moderna sobre Symfony Session mientras mantiene
 * compatibilidad con el sistema legacy (cookies user/logkey).
 *
 * Características:
 * - Usa Symfony Session internamente
 * - CSRF token automático
 * - Regeneración periódica de ID
 * - Cookies seguras (SameSite, httponly)
 * - Mensajes flash nativos de Symfony
 * - Compatibilidad con cookies legacy
 *
 * Uso:
 *   $session = SessionManager::getInstance();
 *   $session->set('key', 'value');
 *   $value = $session->get('key');
 *   $session->flash('success', 'Operación completada');
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class SessionManager
{
    /**
     * Clave de sesión con la firma de la instalación dueña de la sesión.
     */
    private const INSTALL_SIGNATURE_KEY = '_fs_install_signature';

    private static ?SessionManager $instance = null;
    private Session $session;
    private bool $initialized = false;
    private LegacyAuthBridge $legacyAuthBridge;

    /**
     * Constructor privado (singleton)
     */
    private function __construct()
    {
        $this->initialize();
        $this->legacyAuthBridge = new LegacyAuthBridge($this->session);
    }

    /**
     * Obtiene la instancia singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        if (self::$instance instanceof self) {
            self::$instance->getSymfonySession()->clear();
        }

        self::$instance = null;
    }

    /**
     * Inicializa la sesión con Symfony
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Migrar sesión legacy PHPSESSID si existe
        $legacyData = [];
        if (!empty($_COOKIE['PHPSESSID']) && session_status() === PHP_SESSION_NONE) {
            $legacyData = $this->migrateLegacyPhpSession();
        }

        // Si ya hay una sesión PHP activa con el nombre correcto, usarla
        if (session_status() === PHP_SESSION_ACTIVE && session_name() === self::resolveSessionName()) {
            $this->session = new Session(new PhpBridgeSessionStorage(), new AttributeBag(), new FlashBag());
            $this->session->start();
            $this->enforceInstallSessionOwnership();
            $this->initialized = true;
            return;
        }

        // Si hay sesión activa con nombre incorrecto (típicamente PHPSESSID tras migración legacy),
        // envolver con PhpBridgeSessionStorage en lugar de intentar iniciar una nueva.
        // NativeSessionStorage::start() lanzaría "already started by PHP" porque session_status()
        // sigue siendo PHP_SESSION_ACTIVE después de session_write_close().
        if (session_status() === PHP_SESSION_ACTIVE && session_name() !== self::resolveSessionName()) {
            $oldName = session_name();
            $newName = self::resolveSessionName();

            trigger_error(
                '[FSFramework] SessionManager: sesión activa con nombre distinto (' . $oldName
                . '), envolviendo con PhpBridgeSessionStorage y migrando a ' . $newName,
                E_USER_WARNING
            );

            $this->session = new Session(new PhpBridgeSessionStorage(), new AttributeBag(), new FlashBag());
            $this->session->start();

            // Merge de datos legacy migrados
            foreach ($legacyData as $key => $value) {
                $this->session->set($key, $value);
            }
            if (!empty($legacyData)) {
                $this->session->set('_migrated_from_phpsessid', true);
            }

            // Establecer cookie con el nombre correcto para futuros requests.
            // El ID de sesión se reutiliza — el almacenamiento (archivos) usa el ID, no el nombre.
            if (!headers_sent()) {
                $idleTimeout = SessionPolicy::getIdleTimeout();
                $secure = SecureRequestDetector::isSecure();
                $cookiePath = self::resolveCookiePath();
                $currentId = session_id();

                // Expirar la cookie antigua (si migrateLegacyPhpSession no la expiró)
                // para evitar dos cookies de sesión activas que confligirían con session_id()
                if ($oldName !== $newName) {
                    setcookie($oldName, '', [
                        'expires' => time() - 3600,
                        'path' => $cookiePath,
                        'secure' => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                setcookie($newName, $currentId, [
                    'expires' => time() + $idleTimeout,
                    'path' => $cookiePath,
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            $this->maybeRegenerateId();
            $this->initialized = true;
            return;
        }

        if (headers_sent()) {
            $this->session = new Session();
            $this->initialized = true;
            return;
        }

        $idleTimeout = SessionPolicy::getIdleTimeout();
        $secure = SecureRequestDetector::isSecure();
        $sessionName = self::resolveSessionName();

        $options = [
            'name' => $sessionName,
            'cookie_lifetime' => $idleTimeout,
            'cookie_path' => self::resolveCookiePath(),
            'cookie_secure' => $secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'gc_maxlifetime' => SessionPolicy::getAbsoluteTimeout(),
            'use_strict_mode' => true,
        ];

        // Crear storage con handler nativo
        $handler = null;
        if (defined('FS_SESSION_SAVE_PATH') && FS_SESSION_SAVE_PATH) {
            $handler = new NativeFileSessionHandler(FS_SESSION_SAVE_PATH);
        }

        $storage = new NativeSessionStorage($options, $handler);

        $this->session = new Session($storage, new AttributeBag(), new FlashBag());
        $this->session->start();

        // Control de pertenencia ANTES de mezclar datos legacy: si la sesión
        // cargada es de otra instalación, se descarta y se arranca limpia.
        $this->enforceInstallSessionOwnership();

        // Merge de datos legacy migrados via $session->set() (puebla los bags correctamente)
        foreach ($legacyData as $key => $value) {
            $this->session->set($key, $value);
        }
        if (!empty($legacyData)) {
            $this->session->set('_migrated_from_phpsessid', true);
        }

        // Regenerar ID periódicamente
        $this->maybeRegenerateId();

        $this->initialized = true;
    }

    /**
     * Migra datos de sesión desde una cookie PHPSESSID legacy.
     *
     * Detecta si existe la cookie PHPSESSID, abre la sesión legacy,
     * copia $_SESSION, cierra la sesión antigua, expira la cookie
     * y devuelve los datos para que initialize() los mezcle via $session->set().
     *
     * @return array<string, mixed>
     */
    private function migrateLegacyPhpSession(): array
    {
        $legacySessionId = $_COOKIE['PHPSESSID'] ?? '';

        if ($legacySessionId === '') {
            return [];
        }

        $appSessionName = self::resolveSessionName();

        // Abrir sesión legacy PHPSESSID
        session_name('PHPSESSID');
        session_id($legacySessionId);

        $legacyData = [];
        if (@session_start()) {
            $legacyData = $_SESSION;
            session_write_close();
        }

        // Devolverle el mando a la sesión propia: el ID legacy NUNCA puede quedar
        // activo. Sólo se reutiliza el ID de la propia cookie (o ninguno, para que
        // PHP lo resuelva al arrancar la sesión). Si el ID legacy quedara activo,
        // la sesión FSSESS_* arrancaría con el ID ajeno, la aplicación ignoraría
        // su propia cookie y cada petición crearía una sesión nueva — que es lo
        // que vaciaba el `oidc_sso_state` entre init-sso.php y callback.php.
        session_name($appSessionName);
        session_id(self::resolveOwnSessionId($_COOKIE, $appSessionName));

        self::clearLegacyPhpSessionCookie();
        unset($_COOKIE['PHPSESSID']);

        return $legacyData;
    }

    /**
     * ID con el que debe arrancar la sesión propia, tomado únicamente de su
     * propia cookie. Devuelve '' cuando no hay una cookie válida, para que PHP
     * resuelva el ID (o genere uno nuevo) al arrancar la sesión.
     *
     * @param array<string, mixed> $cookies
     *
     * @internal Expuesto para las pruebas de la migración legacy.
     */
    public static function resolveOwnSessionId(array $cookies, string $sessionName): string
    {
        $ownId = trim((string) ($cookies[$sessionName] ?? ''));

        // Mismo patrón con el que Symfony valida un ID de sesión recibido.
        return preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $ownId) === 1 ? $ownId : '';
    }

    /**
     * Rutas con las que hay que expirar la cookie PHPSESSID legacy.
     *
     * Sólo se emite borrado para las rutas que pueden ser de ESTA instalación:
     *
     * - Instalación en la raíz: la PHPSESSID de la raíz es la que usaban las
     *   versiones anteriores de esta propia aplicación, así que se borra.
     * - Instalación en subdirectorio: se borran las dos variantes del path
     *   propio (con y sin barra final, porque el cliente puede haberla creado
     *   con cualquiera de las dos) y NO se toca la de la raíz: una PHPSESSID
     *   con path '/' puede pertenecer a otra aplicación del mismo host y
     *   borrarla cerraría la sesión de esa otra aplicación. La sesión propia ya
     *   no depende de esa cookie (ver migrateLegacyPhpSession).
     *
     * @return list<string>
     *
     * @internal Expuesto para las pruebas de la migración legacy.
     */
    public static function legacyPhpSessionCookiePaths(string $appPath): array
    {
        $appPath = self::normalizeCookiePathValue($appPath);

        if ($appPath === '/') {
            return ['/'];
        }

        return [$appPath, rtrim($appPath, '/')];
    }

    /**
     * Expira la cookie PHPSESSID legacy en el cliente, en todas las rutas donde
     * puede estar viva.
     */
    private static function clearLegacyPhpSessionCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $secure = SecureRequestDetector::isSecure();

        foreach (self::legacyPhpSessionCookiePaths(self::resolveCookiePath()) as $path) {
            setcookie('PHPSESSID', '', [
                'expires' => time() - 3600,
                'path' => $path,
                'secure' => $secure,
                'httponly' => true,
            ]);
        }
    }

    public static function resolveSessionName(): string
    {
        if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
            return trim((string) FS_SESSION_NAME);
        }

        return self::defaultSessionNameFor(self::installRoot());
    }

    /**
     * Raíz de ESTA instalación.
     *
     * Nunca se usa $_SERVER['DOCUMENT_ROOT']: esa es la raíz del vhost y la
     * comparten todas las instalaciones del mismo host (una en la raíz, otra en
     * /fsf/, otra en /staging/), así que derivar de ahí les da el MISMO nombre
     * de sesión y terminan leyendo o pisando la sesión del vecino.
     */
    private static function installRoot(): string
    {
        if (defined('FS_FOLDER') && trim((string) FS_FOLDER) !== '') {
            return (string) FS_FOLDER;
        }

        // Este archivo vive en <raíz-de-la-instalación>/src/Security/.
        return dirname(__DIR__, 2);
    }

    /**
     * Nombre de sesión que le corresponde a una ruta de instalación.
     *
     * @internal Expuesto para las pruebas de unicidad por instalación.
     */
    public static function defaultSessionNameFor(string $installRoot): string
    {
        $seed = str_replace('\\', '/', trim($installRoot));

        return 'FSSESS_' . substr(sha1($seed), 0, 12);
    }

    /**
     * Firma que identifica a ESTA instalación dentro de la sesión.
     *
     * @internal Expuesto para las pruebas del control de pertenencia.
     */
    public static function installSignatureFor(string $installRoot): string
    {
        return sha1(str_replace('\\', '/', trim($installRoot)));
    }

    private function installSignature(): string
    {
        return self::installSignatureFor(self::installRoot());
    }

    /**
     * Falla cerrado si la sesión cargada no es de esta instalación.
     *
     * El nombre de la cookie identifica a la instalación, pero dos
     * instalaciones del mismo host pueden compartirlo (por ejemplo un
     * FS_SESSION_NAME copiado entre configs). En ese caso la sesión almacenada
     * puede pertenecer a la OTRA aplicación, y leerla mezcla estados: el token
     * CSRF, el `state` del SSO o el propio login.
     *
     * Se detecta con una firma de la instalación. Si no coincide, se arranca
     * una sesión limpia SIN destruir la ajena: su archivo queda intacto para su
     * dueño y el browser recibe un id nuevo.
     */
    private function enforceInstallSessionOwnership(): void
    {
        if (!$this->session->isStarted()) {
            return;
        }

        $expected = $this->installSignature();
        $current = $this->session->get(self::INSTALL_SIGNATURE_KEY);

        if ($current === null) {
            $this->session->set(self::INSTALL_SIGNATURE_KEY, $expected);

            return;
        }

        if (is_string($current) && hash_equals($expected, $current)) {
            return;
        }

        error_log(
            '[FSFramework] SessionManager: la sesión cargada pertenece a otra instalación'
            . ' (firma distinta); se arranca una sesión limpia y no se toca la ajena.'
        );

        // Regenerar el id ANTES de limpiar. `session_regenerate_id(false)` guarda
        // el $_SESSION actual en el ID viejo antes de cambiar, así que este orden
        // deja intacta la sesión de la otra instalación y la limpieza afecta sólo
        // al id nuevo. Al revés, los bags ya vaciados se escribirían en el id
        // ajeno y le borrarían el login, el token CSRF y el `state` del SSO.
        if (!$this->session->migrate(false)) {
            // Sin rotación no hay separación posible: se descarta lo cargado sin
            // escribir nada, para no pisar la sesión ajena con datos vacíos.
            $this->discardLoadedSession();

            return;
        }

        $this->session->clear();
        $this->session->set(self::INSTALL_SIGNATURE_KEY, $expected);
    }

    /**
     * Descarta la sesión cargada sin persistir cambios.
     *
     * Se usa cuando el id no se puede rotar: `session_abort()` cierra la sesión
     * sin escribir, así que la otra instalación conserva su archivo tal cual.
     */
    private function discardLoadedSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
    }

    private static function resolveCookiePath(): string
    {
        $preferredPath = defined('FS_PATH') ? (string) FS_PATH : null;
        if ($preferredPath !== null && trim($preferredPath) === '' && empty($_SERVER['REQUEST_URI'])) {
            return '/';
        }

        return self::normalizeCookiePath($preferredPath, $_SERVER);
    }

    private static function normalizeCookiePath(?string $preferredPath, array $server): string
    {
        $candidate = trim((string) $preferredPath);
        if ($candidate !== '') {
            return self::normalizeCookiePathValue($candidate);
        }

        $scriptName = trim((string) ($server['SCRIPT_NAME'] ?? ''));
        if ($scriptName !== '') {
            return self::normalizeCookiePathValue((string) dirname($scriptName));
        }

        $requestUri = filter_var((string) ($server['REQUEST_URI'] ?? '/'), FILTER_SANITIZE_URL);
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            if (str_ends_with($parsedPath, '/index.php')) {
                $parsedPath = substr($parsedPath, 0, -10);
            } else {
                $parsedPath = (string) dirname($parsedPath);
            }

            return self::normalizeCookiePathValue($parsedPath);
        }

        return '/';
    }

    private static function normalizeCookiePathValue(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        if ($normalized === '' || $normalized === '.' || $normalized === '/') {
            return '/';
        }

        $normalized = '/' . ltrim($normalized, '/');

        return str_ends_with($normalized, '/') ? $normalized : $normalized . '/';
    }

    /**
     * Regenera el ID de sesión si ha pasado suficiente tiempo
     */
    private function maybeRegenerateId(): void
    {
        $lastRegen = $this->session->get('_last_regeneration', 0);

        if (time() - $lastRegen > 1800) { // 30 minutos
            $this->regenerateId();
        }
    }

    /**
     * Regenera el ID de sesión
     */
    public function regenerateId(): void
    {
        $this->session->migrate(true);
        $this->session->set('_last_regeneration', time());
    }

    /**
     * Verifica si hay una sesión de usuario activa.
     *
     * When the PHP session still contains user_nick but isValid()
     * fails (idle/absolute expired), we try to re-hydrate from
     * legacy cookies before giving up — fixing the "death window".
     */
    public function isLoggedIn(): bool
    {
        if ($this->legacyAuthBridge->shouldSkipLegacyCookieRestore()) {
            return false;
        }

        $hasNick = $this->session->has('user_nick') && $this->session->get('user_nick');

        if ($hasNick && $this->isValid()) {
            return true;
        }

        $legacyUserData = $this->legacyAuthBridge->getLegacyUserDataFromCookies();
        if ($legacyUserData === null) {
            return false;
        }

        $this->login($legacyUserData);
        return true;
    }

    /**
     * Records user activity — call on every authenticated request
     * so the idle timeout keeps sliding forward.
     */
    public function touch(): void
    {
        $this->session->set('last_activity', time());
    }

    /**
     * Inicia sesión para un usuario
     */
    public function login(array $userData): void
    {
        $this->regenerateId();

        $now = time();
        $this->session->set('user_nick', $userData['nick']);
        $this->session->set('user_email', $userData['email'] ?? null);
        $this->session->set('user_role', ($userData['admin'] ?? false) ? 'admin' : 'user');
        $this->session->set('user_admin', (bool) ($userData['admin'] ?? false));
        $this->session->set('login_time', $now);
        $this->session->set('last_activity', $now);
        $this->session->set('user_logkey', $userData['logkey'] ?? null);
        $this->session->set('user_logged_in', true);

        if (array_key_exists('remember_me', $userData)) {
            $this->session->set('remember_me', (bool) $userData['remember_me']);
        }
    }

    /**
     * Cierra la sesión
     */
    public function logout(): void
    {
        $this->legacyAuthBridge->revokeCurrentLegacyLogin();
        $this->session->invalidate();

        // Limpiar cookies legacy
        $this->legacyAuthBridge->clearLegacyCookies();
    }

    // =========================================================================
    // API de sesión
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    public function all(): array
    {
        return $this->session->all();
    }

    // =========================================================================
    // Flash messages (usando FlashBag de Symfony)
    // =========================================================================

    public function flash(string $type, string $message): void
    {
        $this->session->getFlashBag()->add($type, $message);
    }

    public function getFlashes(string $type = null): array
    {
        if ($type) {
            return $this->session->getFlashBag()->get($type, []);
        }
        return $this->session->getFlashBag()->all();
    }

    public function hasFlashes(string $type = null): bool
    {
        if ($type) {
            return $this->session->getFlashBag()->has($type);
        }
        return count($this->session->getFlashBag()->peekAll()) > 0;
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    public function getCsrfToken(): string
    {
        return CsrfManager::generateToken();
    }

    public function verifyCsrfToken(string $token): bool
    {
        return $token !== '' && CsrfManager::isValid($token);
    }

    public function csrfField(): string
    {
        return CsrfManager::field();
    }

    public function csrfMeta(): string
    {
        return CsrfManager::metaTag();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function getCurrentUserNick(): ?string
    {
        return $this->session->get('user_nick');
    }

    /**
     * Obtiene el rol del usuario actual.
     *
     * Responde desde el snapshot 'user_role' escrito al iniciar sesión, igual
     * que ocurría con 'user_admin': un rol degradado a mitad de sesión sigue
     * apareciendo hasta que la sesión caduque. Para el valor actual usá
     * fs_auth::role(), que relee el usuario desde la base.
     */
    public function getCurrentRole(): string
    {
        return $this->session->get('user_role', 'guest');
    }

    /**
     * Verifica si el usuario actual es administrador.
     *
     * Delega en fs_auth::isAdmin(), la implementación autoritativa, que relee
     * el usuario desde la base en vez de confiar en el snapshot 'user_admin'
     * escrito al iniciar sesión: un administrador degradado a mitad de sesión
     * deja de serlo en la siguiente comprobación. Falla cerrado cuando no hay
     * sesión válida ni fila de usuario.
     *
     * Al delegar, ambas APIs responden lo mismo por construcción.
     */
    public function isAdmin(): bool
    {
        if (!class_exists(\fs_auth::class)) {
            $folder = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
            $path = $folder . '/base/fs_auth.php';

            if (!file_exists($path)) {
                return false;
            }

            require_once $path;
        }

        return class_exists(\fs_auth::class) && \fs_auth::isAdmin();
    }

    public function isValid(): bool
    {
        if (!$this->session->get('user_nick')) {
            return false;
        }

        $loginTime = (int) $this->session->get('login_time', 0);
        $lastActivity = (int) $this->session->get('last_activity', $loginTime);

        return !SessionPolicy::isExpired($loginTime, $lastActivity);
    }

    public function isRememberMe(): bool
    {
        return (bool) $this->session->get('remember_me', false);
    }

    /**
     * Obtiene la sesión Symfony subyacente
     */
    public function getSymfonySession(): Session
    {
        return $this->session;
    }

    public function getLegacyAuthBridge(): LegacyAuthBridge
    {
        return $this->legacyAuthBridge;
    }

    /**
     * Previene clonación
     */
    private function __clone() {}
}
