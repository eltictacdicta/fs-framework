<?php

namespace Tests\Components;

use FSFramework\Core\Plugins;
use FSFramework\Core\StealthMode;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Controller\Concerns\ExtractsMethodBody;

require_once __DIR__ . '/../../base/fs_db2.php';

class StealthModeTest extends TestCase
{
    use ExtractsMethodBody;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_GET = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
    }

    protected function tearDown(): void
    {
        // Cierra la sesión dejada por setUp() para no filtrar una sesión PHPSESSID
        // activa a otros tests de la suite (p. ej. FormHelperTest lo detectaba como warning).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function testHasAccessDeniesWhenSecretIsMissing(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
        ]));

        $this->assertFalse($stealth->hasAccess());
    }

    public function testIsExemptRouteDelegatesToPluginManagedPublicPrefixes(): void
    {
        Plugins::registerPublicPathPrefixes('OidcProvider', ['/oauth', '/account']);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $stealth = new StealthMode($this->createDbStub());

        $_SERVER['REQUEST_URI'] = '/prefix/oauth/test';
        $this->assertFalse($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/oauth';
        $this->assertTrue($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/account';
        $this->assertTrue($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/.well-known/openid-configuration';
        $this->assertFalse($stealth->isExemptRoute());
    }

    public function testHiddenLoginRequiresSecretParameterOnEachAnonymousRequest(): void
    {
        $_SESSION['stealth_unlocked'] = true;
        $_SERVER['REQUEST_URI'] = '/ventas_clientes';

        $stealth = new StealthMode($this->createDbStub([
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));

        $this->assertFalse($stealth->hasAccess());

        $_SERVER['REQUEST_URI'] = '/index.php';
        $this->assertFalse($stealth->hasAccess());

        $_GET = ['page' => 'login', 'adminpanel' => 'secret-token'];
        $_SERVER['REQUEST_URI'] = '/index.php?page=login&adminpanel=secret-token';
        $this->assertTrue($stealth->hasAccess());

        $_GET = ['nlogin' => 'admin', 'adminpanel' => 'secret-token'];
        $_POST = ['user' => 'admin', 'password' => 'pass'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue($stealth->hasAccess());

        $_GET = ['nlogin' => 'admin'];
        $this->assertFalse($stealth->hasAccess());
    }

    public function testIsLegacyLoginRequestAcceptsOnlyTheCanonicalLoginPage(): void
    {
        $stealth = new StealthMode($this->createDbStub());

        $_GET = ['page' => 'login'];
        $this->assertTrue($stealth->isLegacyLoginRequest(), 'page=login is the login page');

        $_GET = ['page' => 'login '];
        $this->assertTrue($stealth->isLegacyLoginRequest(), 'page=login%20 resolves to login for the dispatcher');

        $_GET = ['page' => 'LOGIN'];
        $this->assertFalse($stealth->isLegacyLoginRequest(), 'uppercase LOGIN is not a canonical page');

        $_GET = ['page' => 'admin_info'];
        $this->assertFalse($stealth->isLegacyLoginRequest());

        $_GET = [];
        $this->assertFalse($stealth->isLegacyLoginRequest());

        $_GET = ['page' => ['login']];
        $this->assertFalse($stealth->isLegacyLoginRequest(), 'array input is never a login page');
    }

    public function testIsLegacyLoginSubmissionStaysReachableBehindAVerifiedStealthSecret(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));

        $_GET = ['nlogin' => 'admin', 'adminpanel' => 'secret-token'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->assertTrue($stealth->isLegacyLoginSubmission());

        // The predicate stays consumed by the stealth access path, not only by
        // the gate exemption it was removed from.
        $this->assertTrue($stealth->hasAccess());
    }

    public function testAccessUrlPointsToHiddenLoginEntry(): void
    {
        if (!defined('FS_BASE_URL')) {
            define('FS_BASE_URL', 'https://oidcprovider.ddev.site');
        }

        $stealth = new StealthMode($this->createDbStub([
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));

        $this->assertSame(
            'https://oidcprovider.ddev.site/index.php?adminpanel=secret-token',
            $stealth->getAccessUrl()
        );

        $this->assertSame(
            '/index.php?page=login&adminpanel=secret-token',
            $stealth->getHiddenLoginUrl()
        );
    }

    public function testSaveCustomCssRejectsDangerousRules(): void
    {
        $stealth = new StealthMode($this->createDbStub());

        $this->assertFalse($stealth->saveCustomCss('@import url("https://evil.example/a.css");'));
        $this->assertFalse($stealth->saveCustomCss('.hero { background-image: url("javascript:alert(1)"); }'));
        $this->assertFalse($stealth->saveCustomCss('.hero { width: expression(alert(1)); }'));
    }

    public function testSaveCustomCssPersistsSafeStylesheet(): void
    {
        $db = $this->createDbStub();
        $stealth = new StealthMode($db);

        $saved = $stealth->saveCustomCss('.hero { color: #123456; background-image: url("/img/bg.png"); }');

        $this->assertTrue($saved);
        $this->assertStringContainsString('background-image', $db->lastExecSql);
        $this->assertStringContainsString('varchar', $db->lastExecSql);
    }

    public function testSaveHomepageHtmlRemovesInlineScriptsAndUnsafeUrls(): void
    {
        $db = $this->createDbStub();
        $stealth = new StealthMode($db);

        $saved = $stealth->saveHomepageHtml('<div onclick="alert(1)"><a href="javascript:alert(1)">bad</a><script>alert(1)</script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></div>');

        $this->assertTrue($saved);
        $this->assertStringNotContainsString('onclick', $db->lastExecSql);
        $this->assertStringNotContainsString('javascript:alert(1)', $db->lastExecSql);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $db->lastExecSql);
        $this->assertStringContainsString('cdn.jsdelivr.net', $db->lastExecSql);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEnsurePhpSessionStartedSetsSessionName(): void
    {
        // Simula define faltante en proceso aislado
        if (!defined('FS_FOLDER')) {
            define('FS_FOLDER', '/var/www/html');
        }

        // Cierra la sesión iniciada por setUp() para forzar el camino
        // session_status() === PHP_SESSION_NONE en ensurePhpSessionStarted()
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $stealth = new StealthMode($this->createDbStub());

        $ref = new ReflectionClass(StealthMode::class);
        $method = $ref->getMethod('ensurePhpSessionStarted');
        $method->setAccessible(true);
        // ensurePhpSessionStarted() ya no emite error_log (el diagnóstico vivía en
        // el cookie-sync eliminado). La redirección se mantiene para que cualquier
        // aviso de PHP durante el start no cuente como output del subproceso.
        $prevErrorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $method->invoke($stealth);
        } finally {
            ini_set('error_log', $prevErrorLog);
        }

        $expectedName = SessionManager::resolveSessionName();
        $this->assertSame($expectedName, session_name(), 'session_name() debe coincidir con resolveSessionName() después de ensurePhpSessionStarted()');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'La sesión debe quedar activa después de ensurePhpSessionStarted()');
    }

    /**
     * STH-02/STH-03 — the parameters are in force BEFORE the session starts.
     *
     * The CLI SAPI cannot observe `Set-Cookie` (headers_list() is empty), so
     * this asserts the input seam the application supplies to the session:
     * session_get_cookie_params() after the call is the attribute set that was
     * applied before session_start(), and session_name() can only be the shared
     * value if it was set before the start (PHP refuses to rename an active
     * session).
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEnsurePhpSessionStartedAppliesTheSharedCookieParamsAndStrictModeBeforeTheStart(): void
    {
        if (!defined('FS_FOLDER')) {
            define('FS_FOLDER', '/var/www/html');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Prove strict mode is off first: the method must turn it on.
        ini_set('session.use_strict_mode', '0');

        $stealth = new StealthMode($this->createDbStub());

        $ref = new ReflectionClass(StealthMode::class);
        $method = $ref->getMethod('ensurePhpSessionStarted');
        $method->setAccessible(true);

        $prevErrorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $method->invoke($stealth);
        } finally {
            ini_set('error_log', $prevErrorLog);
        }

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame(SessionManager::resolveSessionName(), session_name());

        $applied = session_get_cookie_params();
        $expected = SessionManager::sessionCookieParams();

        $this->assertSame($expected['path'], $applied['path'], 'The path must come from the shared resolver, not a hardcoded /.');
        $this->assertSame($expected['lifetime'], $applied['lifetime']);
        $this->assertTrue($applied['httponly']);
        $this->assertSame('Lax', $applied['samesite']);
        $this->assertSame(
            $expected['secure'],
            $applied['secure'],
            'Secure must follow the detected transport through the shared definition.'
        );

        $this->assertSame('1', ini_get('session.use_strict_mode'), 'STH-03: strict mode must be on before the start.');
    }

    /**
     * STH-02 — the duplicate emission is REMOVED, not reconciled.
     *
     * A source assertion because a second setcookie() for the same cookie name
     * is unobservable in-process: it would be a header, and CLI keeps no header
     * list. The live count is the operator probe O.3.
     */
    public function testEnsurePhpSessionStartedDoesNotEmitItsOwnSessionCookie(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/StealthMode.php');
        $body = $this->methodBody($source, 'ensurePhpSessionStarted');

        $this->assertStringNotContainsString('setcookie(', $body);
        $this->assertStringNotContainsString("'samesite' =>", $body, 'A local attribute literal is a second copy of the cookie attribute set.');
        $this->assertStringContainsString('sessionCookieParams(', $body);
    }

    /**
     * @return \fs_db2&object{lastExecSql: string}
     */
    private function createDbStub(array $settings = []): \fs_db2
    {
        return new class($settings) extends \fs_db2 {
            private array $settings = [];
            public string $lastExecSql = '';

            public function __construct(array $settings = [])
            {
                $this->settings = $settings;
            }

            public function connected()
            {
                return true;
            }

            public function connect()
            {
                return true;
            }

            public function escape_string($str)
            {
                return addslashes((string) $str);
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function select($sql, $params = [])
            {
                if (str_contains($sql, 'WHERE name IN')) {
                    $rows = [];
                    foreach ((array) $this->settings as $name => $value) {
                        $rows[] = ['name' => $name, 'varchar' => $value];
                    }

                    return $rows;
                }

                if (preg_match("/WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $name = stripslashes($matches[1]);
                    return array_key_exists($name, $this->settings) ? [['name' => $name]] : [];
                }

                return [];
            }

            public function exec($sql, $transaction = null, $params = [], $batch = false)
            {
                $this->lastExecSql = $sql;

                if (preg_match("/UPDATE fs_vars SET [`\"]varchar[`\"] = '(.+)' WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[2])] = stripslashes($matches[1]);
                }

                if (preg_match("/INSERT INTO fs_vars \(name, [`\"]varchar[`\"]\) VALUES \('([^']+)', '(.+)'\)/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[1])] = stripslashes($matches[2]);
                }

                return true;
            }
        };
    }
}
