<?php
/**
 * Minimal maintenance mode guard that can run before Composer or the kernel.
 */
final class fs_maintenance_mode
{
    private const DEFAULT_LOCK_FILE = 'maintenance.json';
    private const DEFAULT_BYPASS_QUERY_PARAM = 'fs_maintenance_bypass';
    private const DEFAULT_MYSQL_PORT = '3306';
    private const DEFAULT_POSTGRESQL_PORT = '5432';
    private const STEALTH_DEFAULT_PARAM_NAME = 'adminpanel';
    private const STEALTH_VAR_ENABLED = 'stealth_enabled';
    private const STEALTH_VAR_PARAM_NAME = 'stealth_param_name';
    private const STEALTH_VAR_PARAM_VALUE = 'stealth_param_value';
    private const BACKEND_ALLOWED_PAGES = [
        'admin_home',
        'admin_info',
        'admin_email',
        'admin_orden_menu',
        'admin_user',
        'admin_users',
        'admin_rol',
        'admin_stealth',
        'admin_system_branding',
        'admin_agente',
        'admin_agentes',
        'admin_updater',
        'admin_plugin_store',
    ];

    private static ?array $stealthSettings = null;

    public static function isActive(?array $server = null, ?array $query = null, ?array $session = null, ?array $post = null): bool
    {
        $server = $server ?? $_SERVER;
        $query = $query ?? $_GET;
        $post = $post ?? $_POST;

        if (!self::isForced() && !self::hasLock()) {
            return false;
        }

        if (self::hasBypass($server, $query)) {
            return false;
        }

        if (self::hasAdminSession($session)) {
            return false;
        }

        if (self::isBackendPageRequest($query)) {
            return false;
        }

        if (self::isLoginAccessRequest($server, $query, $post)) {
            return false;
        }

        if (self::hasStealthAdminAccess($server, $query, $post)) {
            return false;
        }

        return true;
    }

    public static function isForced(): bool
    {
        return self::toBool(defined('FS_FORCE_MAINTENANCE') ? FS_FORCE_MAINTENANCE : false);
    }

    public static function isEnabled(): bool
    {
        return self::isForced() || self::hasLock();
    }

    public static function hasLock(): bool
    {
        $state = self::readLockState();
        if ($state === null) {
            return false;
        }

        if (isset($state['active'])) {
            return self::toBool($state['active']);
        }

        return true;
    }

    public static function lockFilePath(): string
    {
        if (defined('FS_MAINTENANCE_LOCK_FILE')) {
            $customPath = trim((string) FS_MAINTENANCE_LOCK_FILE);
            if ($customPath !== '') {
                return $customPath;
            }
        }

        $tmpName = defined('FS_TMP_NAME') ? trim((string) FS_TMP_NAME, '/\\') : '';
        $tmpDir = FS_FOLDER . '/tmp';
        if ($tmpName !== '') {
            $tmpDir .= '/' . $tmpName;
        }

        return $tmpDir . '/' . self::DEFAULT_LOCK_FILE;
    }

    public static function lockFileName(): string
    {
        return basename(self::lockFilePath());
    }

    public static function bypassQueryParam(): string
    {
        if (defined('FS_MAINTENANCE_BYPASS_QUERY_PARAM')) {
            $name = trim((string) FS_MAINTENANCE_BYPASS_QUERY_PARAM);
            if ($name !== '') {
                return $name;
            }
        }

        return self::DEFAULT_BYPASS_QUERY_PARAM;
    }

    public static function hasBypass(?array $server = null, ?array $query = null): bool
    {
        $token = self::bypassToken();
        if ($token === '') {
            return false;
        }

        $server = $server ?? $_SERVER;
        $query = $query ?? $_GET;
        $param = self::bypassQueryParam();
        $candidate = isset($query[$param]) ? trim((string) $query[$param]) : '';
        if ($candidate === '' || !hash_equals($token, $candidate)) {
            return false;
        }

        $allowedIps = self::allowedBypassIps();
        if ($allowedIps === []) {
            return true;
        }

        $remoteAddress = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress === '') {
            return false;
        }

        foreach ($allowedIps as $allowedIp) {
            if ($allowedIp === '*' || $allowedIp === $remoteAddress) {
                return true;
            }
        }

        return false;
    }

    public static function readLockState(): ?array
    {
        $path = self::lockFilePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['active' => true];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['active' => true];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['active' => true, 'message' => $trimmed];
    }

    public static function stealthAccessStatus(): array
    {
        $settings = self::readStealthSettings();
        $paramName = trim((string) ($settings['param_name'] ?? self::STEALTH_DEFAULT_PARAM_NAME));
        if ($paramName === '') {
            $paramName = self::STEALTH_DEFAULT_PARAM_NAME;
        }

        $paramValue = trim((string) ($settings['param_value'] ?? ''));
        $enabled = isset($settings['enabled']) && self::toBool($settings['enabled']);

        return [
            'enabled' => $enabled,
            'param_name' => $paramName,
            'param_value' => $paramValue,
            'ready' => $enabled && $paramValue !== '',
        ];
    }

    public static function message(): string
    {
        $state = self::readLockState();
        if (is_array($state) && !empty($state['message'])) {
            return trim((string) $state['message']);
        }

        return 'El sistema está temporalmente en mantenimiento. Inténtelo de nuevo en unos minutos.';
    }

    public static function retryAfter(): int
    {
        $state = self::readLockState();
        if (is_array($state) && isset($state['retry_after'])) {
            $retryAfter = (int) $state['retry_after'];
            if ($retryAfter > 0) {
                return $retryAfter;
            }
        }

        return 300;
    }

    public static function writeLock(array $state = []): bool
    {
        $path = self::lockFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $payload = array_merge([
            'active' => true,
            'updated_at' => date('c'),
        ], $state);

        $tmpPath = $path . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            return false;
        }

        if (!rename($tmpPath, $path)) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            return false;
        }

        return true;
    }

    public static function clearLock(): bool
    {
        $path = self::lockFilePath();
        if (!file_exists($path)) {
            return true;
        }

        return unlink($path);
    }

    public static function hasAdminSession(?array $session = null): bool
    {
        $session = $session ?? self::readSessionSnapshot();
        if (!is_array($session) || $session === []) {
            return false;
        }

        $legacyAdmin = isset($session['user_admin']) && self::toBool($session['user_admin']);
        $legacyRole = strtolower(trim((string) ($session['user_role'] ?? '')));
        $legacyLoggedIn = isset($session['user_logged_in']) ? self::toBool($session['user_logged_in']) : isset($session['user_nick']);
        if (($legacyAdmin || $legacyRole === 'admin') && $legacyLoggedIn && self::isSessionStillValid($session)) {
            return true;
        }

        $attributes = $session['_sf2_attributes'] ?? null;
        if (!is_array($attributes)) {
            return false;
        }

        $modernAdmin = isset($attributes['user_admin']) && self::toBool($attributes['user_admin']);
        $modernRole = strtolower(trim((string) ($attributes['user_role'] ?? '')));
        $modernLoggedIn = isset($attributes['user_logged_in'])
            ? self::toBool($attributes['user_logged_in'])
            : isset($attributes['user_nick']);

        return ($modernAdmin || $modernRole === 'admin')
            && $modernLoggedIn
            && self::isSessionStillValid($attributes);
    }

    public static function hasStealthAdminAccess(?array $server = null, ?array $query = null, ?array $post = null): bool
    {
        $server = $server ?? $_SERVER;
        $query = $query ?? $_GET;
        $post = $post ?? $_POST;

        $settings = self::readStealthSettings();
        if (($settings['enabled'] ?? false) !== true) {
            return false;
        }

        $paramName = $settings['param_name'] ?? self::STEALTH_DEFAULT_PARAM_NAME;
        $paramValue = $settings['param_value'] ?? '';
        $requestValue = isset($query[$paramName]) ? trim((string) $query[$paramName]) : '';
        if ($paramValue === '' || $requestValue === '' || !hash_equals($paramValue, $requestValue)) {
            return false;
        }

        if (self::isStealthRootRequest($server, $query)) {
            return true;
        }

        if (($query['page'] ?? '') === 'login') {
            return true;
        }

        return self::isStealthLoginSubmission($server, $query, $post);
    }

    private static function bypassToken(): string
    {
        return defined('FS_MAINTENANCE_BYPASS_TOKEN') ? trim((string) FS_MAINTENANCE_BYPASS_TOKEN) : '';
    }

    private static function readStealthSettings(): array
    {
        if (defined('FS_MAINTENANCE_STEALTH_ENABLED')) {
            return [
                'enabled' => self::toBool(FS_MAINTENANCE_STEALTH_ENABLED),
                'param_name' => defined('FS_MAINTENANCE_STEALTH_PARAM_NAME')
                    ? trim((string) FS_MAINTENANCE_STEALTH_PARAM_NAME)
                    : self::STEALTH_DEFAULT_PARAM_NAME,
                'param_value' => defined('FS_MAINTENANCE_STEALTH_PARAM_VALUE')
                    ? trim((string) FS_MAINTENANCE_STEALTH_PARAM_VALUE)
                    : '',
            ];
        }

        if (self::$stealthSettings !== null) {
            return self::$stealthSettings;
        }

        self::$stealthSettings = [
            'enabled' => false,
            'param_name' => self::STEALTH_DEFAULT_PARAM_NAME,
            'param_value' => '',
        ];

        if (!defined('FS_DB_TYPE') || !defined('FS_DB_HOST') || !defined('FS_DB_NAME') || !defined('FS_DB_USER') || !defined('FS_DB_PASS')) {
            return self::$stealthSettings;
        }

        try {
            $pdo = self::createStealthPdo();
            if ($pdo === null) {
                return self::$stealthSettings;
            }

            $quotedVarchar = self::quoteStealthColumnName();
            $sql = 'SELECT name, ' . $quotedVarchar . ' FROM fs_vars WHERE name IN (:enabled, :param_name, :param_value)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':enabled' => self::STEALTH_VAR_ENABLED,
                ':param_name' => self::STEALTH_VAR_PARAM_NAME,
                ':param_value' => self::STEALTH_VAR_PARAM_VALUE,
            ]);

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $name = $row['name'] ?? '';
                $value = (string) ($row['varchar'] ?? '');
                if ($name === self::STEALTH_VAR_ENABLED) {
                    self::$stealthSettings['enabled'] = self::toBool($value);
                } elseif ($name === self::STEALTH_VAR_PARAM_NAME && $value !== '') {
                    self::$stealthSettings['param_name'] = $value;
                } elseif ($name === self::STEALTH_VAR_PARAM_VALUE) {
                    self::$stealthSettings['param_value'] = $value;
                }
            }
        } catch (\Throwable $exception) {
            self::$stealthSettings = [
                'enabled' => false,
                'param_name' => self::STEALTH_DEFAULT_PARAM_NAME,
                'param_value' => '',
            ];
        }

        return self::$stealthSettings;
    }

    private static function quoteStealthColumnName(): string
    {
        $dbType = strtoupper(trim((string) FS_DB_TYPE));

        if ($dbType === 'POSTGRESQL' || $dbType === 'POSTGRES' || $dbType === 'PGSQL') {
            return '"varchar"';
        }

        return '`varchar`';
    }

    private static function createStealthPdo(): ?\PDO
    {
        $dbType = strtoupper(trim((string) FS_DB_TYPE));
        $port = self::resolveDatabasePort($dbType);

        if ($dbType === 'MYSQL') {
            return new \PDO(
                'mysql:host=' . FS_DB_HOST . ';port=' . $port . ';dbname=' . FS_DB_NAME . ';charset=utf8mb4',
                FS_DB_USER,
                FS_DB_PASS,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ]
            );
        }

        if ($dbType === 'POSTGRESQL' || $dbType === 'POSTGRES' || $dbType === 'PGSQL') {
            return new \PDO(
                'pgsql:host=' . FS_DB_HOST . ';port=' . $port . ';dbname=' . FS_DB_NAME,
                FS_DB_USER,
                FS_DB_PASS,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ]
            );
        }

        return null;
    }

    private static function isStealthRootRequest(array $server, array $query): bool
    {
        if (!empty($query['page'])) {
            return false;
        }

        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        $allowedPaths = ['/'];
        if ($base !== '') {
            $allowedPaths[] = $base;
            $allowedPaths[] = $base . '/index.php';
        } else {
            $allowedPaths[] = '/index.php';
        }

        return in_array($path, $allowedPaths, true);
    }

    private static function isLoginAccessRequest(array $server, array $query, array $post): bool
    {
        if (($query['page'] ?? '') === 'login') {
            return true;
        }

        if (self::isOidcLoginRequest($server)) {
            return true;
        }

        return self::isStealthLoginSubmission($server, $query, $post);
    }

    private static function isBackendPageRequest(array $query): bool
    {
        $page = strtolower(trim((string) ($query['page'] ?? '')));
        if ($page === '') {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $page) !== 1) {
            return false;
        }

        return in_array($page, self::BACKEND_ALLOWED_PAGES, true);
    }

    private static function isOidcLoginRequest(array $server): bool
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        $allowedPaths = [
            '/oauth',
            '/oauth/login',
        ];

        if ($base !== '') {
            $allowedPaths[] = $base . '/oauth';
            $allowedPaths[] = $base . '/oauth/login';
        }

        return in_array($path, $allowedPaths, true);
    }

    private static function isStealthLoginSubmission(array $server, array $query, array $post): bool
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return false;
        }

        if (isset($query['nlogin'])) {
            return true;
        }

        return isset($post['user']) && (isset($post['password']) || isset($post['email']));
    }

    private static function readSessionSnapshot(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return is_array($_SESSION ?? null) ? $_SESSION : [];
        }

        foreach (self::resolveSessionNames() as $sessionName) {
            $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
            if ($sessionId === '') {
                continue;
            }

            if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
                session_save_path(trim((string) FS_SESSION_SAVE_PATH));
            }

            session_name($sessionName);
            session_id($sessionId);

            if (PHP_VERSION_ID >= 70100) {
                @session_start(['read_and_close' => true]);
            } else {
                @session_start();
            }

            $session = is_array($_SESSION ?? null) ? $_SESSION : [];

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($session !== []) {
                return $session;
            }
        }

        return [];
    }

    private static function resolveSessionName(): string
    {
        if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
            return trim((string) FS_SESSION_NAME);
        }

        $seed = defined('FS_FOLDER') ? (string) FS_FOLDER : (string) ($_SERVER['SCRIPT_FILENAME'] ?? __DIR__);
        $seed = str_replace('\\', '/', $seed);

        return 'FSSESS_' . substr(sha1($seed), 0, 12);
    }

    /**
     * @return array<int,string>
     */
    private static function resolveSessionNames(): array
    {
        $names = [self::resolveSessionName()];
        $currentSessionName = session_name();
        if ($currentSessionName !== '') {
            $names[] = $currentSessionName;
        }

        $iniSessionName = trim((string) ini_get('session.name'));
        if ($iniSessionName !== '') {
            $names[] = $iniSessionName;
        }

        $names[] = 'PHPSESSID';

        $normalized = [];
        foreach ($names as $name) {
            $candidate = trim((string) $name);
            if ($candidate !== '' && !in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private static function isSessionStillValid(array $session): bool
    {
        $loginTime = isset($session['login_time']) ? (int) $session['login_time'] : 0;
        $lastActivity = isset($session['last_activity']) ? (int) $session['last_activity'] : $loginTime;

        if ($loginTime <= 0 && $lastActivity <= 0) {
            return true;
        }

        $maxLifetime = defined('FS_SESSION_LIFETIME') ? (int) FS_SESSION_LIFETIME : 7200;
        return (time() - max($loginTime, $lastActivity)) <= $maxLifetime;
    }

    /**
     * @return array<int, string>
     */
    private static function allowedBypassIps(): array
    {
        if (!defined('FS_MAINTENANCE_BYPASS_IPS')) {
            return [];
        }

        $raw = FS_MAINTENANCE_BYPASS_IPS;
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[\s,]+/', trim((string) $raw)) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $candidate = trim((string) $item);
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function resolveDatabasePort(string $dbType): string
    {
        if (defined('FS_DB_PORT')) {
            $configuredPort = trim((string) FS_DB_PORT);
            if ($configuredPort !== '') {
                return $configuredPort;
            }
        }

        if ($dbType === 'POSTGRESQL' || $dbType === 'POSTGRES' || $dbType === 'PGSQL') {
            return self::DEFAULT_POSTGRESQL_PORT;
        }

        return self::DEFAULT_MYSQL_PORT;
    }
}
