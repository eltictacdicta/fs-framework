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

        // La cookie PHPSESSID de otra aplicación del host sólo se limpia: NUNCA
        // se abre esa sesión ni se copian sus datos (ver discardLegacyPhpSession).
        // Salvo que el nombre de sesión propio SEA PHPSESSID (FS_SESSION_NAME):
        // ahí esa cookie es la propia y no se toca.
        if (!empty($_COOKIE['PHPSESSID']) && self::resolveSessionName() !== 'PHPSESSID') {
            $this->discardLegacyPhpSession();
        }

        // Si ya hay una sesión PHP activa con el nombre correcto, usarla
        if (session_status() === PHP_SESSION_ACTIVE && session_name() === self::resolveSessionName()) {
            $this->session = new Session(new PhpBridgeSessionStorage(), new AttributeBag(), new FlashBag());
            $this->session->start();
            $this->enforceInstallSessionOwnership();
            $this->initialized = true;
            return;
        }

        // Si hay una sesión activa con OTRO nombre (session.auto_start, código
        // legacy o un plugin), NO se reutiliza ni se envuelve: sus datos son de
        // otra sesión, y adoptarlos dejaba inyectar un login porque isValid()
        // sólo valida `user_nick` + `login_time` + `last_activity`. Se aborta sin
        // escribir nada y se sigue por la vía nativa, que arranca la sesión
        // propia (y aplica el control de pertenencia). session_abort() deja el
        // estado en PHP_SESSION_NONE, así que NativeSessionStorage::start() no
        // lanza el "already started by PHP" que motivaba la rama anterior.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
            session_id('');
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

        // El id con el que arranca la sesión propia sale SIEMPRE de su propia
        // cookie: un id residual (de una sesión cerrada por otro componente)
        // ganaría sobre la cookie y haría leer la sesión de otro.
        self::selectOwnSessionId();

        $this->session = new Session($storage, new AttributeBag(), new FlashBag());
        $this->session->start();

        // Control de pertenencia: si la sesión cargada es de otra instalación,
        // se descarta y se arranca una limpia antes de usar nada de ella.
        $this->enforceInstallSessionOwnership();

        // Regenerar ID periódicamente
        $this->maybeRegenerateId();

        $this->initialized = true;
    }

    /**
     * Deja como id activo el de la propia cookie de sesión (o ninguno).
     *
     * PHP reutiliza un `session_id()` ya fijado antes de `session_start()` y en
     * ese caso NO lee la cookie: un id residual (de una sesión PHPSESSID que
     * abrió y cerró otro componente) haría que la sesión propia se abra con el
     * archivo de otro (`sess_<id>` en el `save_path` compartido) y herede su
     * `user_nick`, su `login_time` y su `last_activity` — justo lo que valida
     * {@see isValid()}. Por eso el id se resuelve acá, siempre desde la cookie
     * propia, y se limpia si no hay una válida para que PHP la lea.
     */
    private static function selectOwnSessionId(): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        $ownId = $_COOKIE[self::resolveSessionName()] ?? $_COOKIE[self::legacySessionName()] ?? '';
        $ownId = is_string($ownId) ? trim($ownId) : '';

        // Mismo patrón con el que Symfony valida un id de sesión recibido.
        session_id(preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $ownId) === 1 ? $ownId : '');
    }

    /**
     * Descarta la cookie de sesión legacy (PHPSESSID) sin leer la sesión ajena.
     *
     * Antes esta ruta abría la sesión PHPSESSID y copiaba sus claves dentro de
     * la nuestra ("migración legacy"). Eso permitía inyectar un login: un
     * `user_nick` ajeno con `login_time` y `last_activity` no expirados es
     * exactamente lo único que valida {@see isValid()}, así que cualquier otra
     * aplicación del mismo host con el `session.save_path` compartido (o
     * cualquier primitiva de escritura de sesión) alcanzaba para entrar.
     *
     * La continuidad después del renombrado de la cookie sigue cubierta por las
     * cookies firmadas `user`/`logkey`/`auth_sig`, que sí se validan contra la
     * base de datos antes de reconstruir la sesión.
     */
    private function discardLegacyPhpSession(): void
    {
        self::clearLegacyPhpSessionCookie();

        unset($_COOKIE['PHPSESSID']);
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
     *   no depende de esa cookie (ver discardLegacyPhpSession).
     *
     * @return list<string>
     *
     * @internal Expuesto para las pruebas de la limpieza legacy.
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

        return self::defaultSessionNameFor(self::installSeed());
    }

    /**
     * Semilla de identidad de ESTA instalación.
     *
     * Es el secreto por instalación que ya persiste el SecretManager (único y
     * estable entre deploys): así dos instalaciones no comparten nombre de
     * sesión y mover la app de carpeta no lo cambia. Si el secreto no está
     * disponible, se degrada a la raíz de la instalación.
     *
     * @internal Expuesto para las pruebas.
     */
    public static function installSeed(): string
    {
        try {
            return 'session:' . SecretManager::getSecret();
        } catch (\Throwable) {
            return self::installRoot();
        }
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

    /**
     * Firma de pertenencia de ESTA instalación (semilla = secreto por
     * instalación, como el nombre de sesión).
     *
     * @internal Expuesto para las pruebas.
     */
    public static function installSignature(): string
    {
        return sha1(self::installSeed());
    }

    /**
     * Nombre de sesión previo (seed = raíz de la instalación) y su firma: se
     * usan para migrar sin desloguear a quienes venían de esa versión.
     *
     * @internal Expuesto para las pruebas.
     */
    public static function legacySessionName(): string
    {
        return self::defaultSessionNameFor(self::installRoot());
    }

    /**
     * @internal Expuesto para las pruebas.
     */
    public static function legacyInstallSignature(): string
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

        $expected = self::installSignature();
        $current = $this->session->get(self::INSTALL_SIGNATURE_KEY);

        if ($current === null) {
            $this->session->set(self::INSTALL_SIGNATURE_KEY, $expected);

            return;
        }

        if (is_string($current) && (hash_equals($expected, $current) || hash_equals(self::legacyInstallSignature(), $current))) {
            // Re-sella con la firma nueva cuando venía la anterior (migración del
            // seed de la ruta al secreto por instalación): así el siguiente
            // request ya no vuelve a pasar por la rama legacy.
            if (!hash_equals($expected, $current)) {
                $this->session->set(self::INSTALL_SIGNATURE_KEY, $expected);
            }

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
