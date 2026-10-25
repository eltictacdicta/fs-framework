<?php

declare(strict_types=1);

/**
 * VULN-0008 (CWE-306) / RMR-01…RMR-04 — remember-me revocation authority.
 *
 * A single anonymous request that carries the *unsigned* `user` cookie used to
 * name the account whose `fs_users.log_key` was rotated, wrote an audit record
 * for it and read that account's stored `last_ip` back into the anonymous login
 * page. Rotation of a per-account server-side secret is an authority-bearing
 * action, so it must be driven by an authenticated session only; a failed
 * remember-me verification is a fact about the request, and it may only clear
 * the caller's own cookies and session.
 *
 * Testing seams, and why they are these ones:
 *
 *  1. `LegacyAuthBridge` resolves the account through the legacy global
 *     `fs_user` — `class_exists('fs_user')` then `new fs_user()`
 *     (src/Security/LegacyAuthBridge.php:158-165) — with no injection point, so
 *     the double is installed under that exact global name before the bridge
 *     runs. That is only possible in an isolated process (the shared suite
 *     loads the real `fs_user` for other tests) and it is what keeps these
 *     tests off the real database: a real `fs_user` would look the named
 *     account up and, for an existing nickname, rotate its `log_key` for real.
 *  2. `fs_login::handleInvalidCookieSession()` is private, and its caller
 *     (`log_in_cookie()`) reads the cookies through
 *     `filter_input(INPUT_COOKIE, …)`, which returns `null` under the CLI SAPI.
 *     The cookie *read* cannot be reproduced here, so the handler that changed
 *     is exercised directly.
 *  3. `log_out(TRUE)` is observed through an `fs_login` double, never through
 *     `Set-Cookie`: the CLI SAPI cannot observe headers. The header-level half
 *     of the contract is a live HTTP probe owed by the operator.
 */

namespace Tests\Security;

use FSFramework\Security\CookieSigner;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_cache.php';
require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_login.php';

class AnonymousLogoutRevocationTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingFsUser::$instances = 0;
        RecordingFsUser::$lookups = [];
        RecordingFsUser::$accountExists = true;
        RecordingAccount::$rotations = [];
        RecordingAccount::$saves = [];

        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR']);
    }

    // =====================================================================
    // RMR-01 / RMR-02: an anonymous request mutates nothing but the caller
    // =====================================================================

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anonymousRememberMeFailureDoesNotTouchTheNamedAccount(): void
    {
        $this->installLegacyUserDouble();

        $_COOKIE['user'] = 'named-account';

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger);

        $this->callPrivate($login, 'handleInvalidCookieSession', [$this->makeStoredAccount('named-account')]);

        $this->assertSame(
            [],
            RecordingFsUser::$lookups,
            'La cuenta nombrada por la cookie sin firmar no puede ser ni consultada'
        );
        $this->assertSame([], RecordingAccount::$rotations, 'No se rota el log_key de una cuenta que la petición sólo nombra');
        $this->assertSame([], RecordingAccount::$saves, 'No se guarda nada de la cuenta nombrada');
        $this->assertSame(0, RecordingFsUser::$instances, 'La petición anónima no debe instanciar el modelo de usuarios');
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anonymousRememberMeFailureClearsOnlyTheCallersOwnSession(): void
    {
        $this->installLegacyUserDouble();

        $logger = $this->makeLogger();
        $login = $this->makeLogoutRecordingLogin($logger);

        // Both branches of the handler: the requester's IP equal to the stored
        // one, and different from it. Neither may leave the caller's own stale
        // cookie in place, so both must ask log_out() to remove it.
        foreach (['127.0.0.1', '203.0.113.7'] as $storedIp) {
            $login->logoutCalls = [];

            $this->callPrivate(
                $login,
                'handleInvalidCookieSession',
                [$this->makeStoredAccount('named-account', $storedIp)]
            );

            $this->assertSame(
                [true],
                $login->logoutCalls,
                'La limpieza del propio estado debe invocarse con el flag remember-me (log_out(TRUE)) para IP ' . $storedIp
            );
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anonymousRememberMeFailurePerformsNoLookupForAnUnknownNickname(): void
    {
        $this->installLegacyUserDouble();

        RecordingFsUser::$accountExists = false;
        $_COOKIE['user'] = 'ghost-account';

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger);

        $this->callPrivate($login, 'handleInvalidCookieSession', [$this->makeStoredAccount('ghost-account')]);

        $this->assertSame([], RecordingFsUser::$lookups, 'Un nick inexistente tampoco puede provocar una consulta');
        $this->assertSame([], RecordingAccount::$rotations);
        $this->assertCount(1, $logger->messages, 'El llamante recibe su propio aviso neutro');
    }

    // =====================================================================
    // RMR-03: audit attribution comes from the session
    // =====================================================================

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anonymousRequestCannotAttributeAnAuditRecordToTheNamedAccount(): void
    {
        $this->installLegacyUserDouble();

        $_COOKIE['user'] = 'named-account';

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger);

        $this->callPrivate($login, 'handleInvalidCookieSession', [$this->makeStoredAccount('named-account')]);

        $this->assertNotContains(
            'named-account',
            $logger->userNicks,
            'fs_logs.usuario no puede salir de un valor aportado por la petición'
        );
        $this->assertSame([null], $logger->userNicks);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function authenticatedLogoutAttributesTheAuditRecordToTheSessionNickname(): void
    {
        $this->installLegacyUserDouble();

        // The unsigned cookie names somebody else; the session names the actor.
        $_COOKIE['user'] = 'someone-else';
        $_COOKIE['logkey'] = 'someone-elses-key';

        $session = $this->makeSession();
        $session->set('user_nick', 'session-owner');
        $this->setBridgedSessionNick('session-owner');

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger, $session);

        $this->callPrivate($login, 'log_out', [true]);

        $this->assertSame(['session-owner'], $logger->userNicks, 'El registro de auditoría nombra a la sesión autenticada');
        $this->assertNotContains('someone-else', $logger->userNicks);
        $this->assertContains('El usuario ha cerrado la sesión.', $logger->saved);
    }

    // =====================================================================
    // RMR-01: an authenticated logout still rotates the key
    // =====================================================================

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function authenticatedLogoutStillRotatesTheKeyExactlyOnce(): void
    {
        $this->installLegacyUserDouble();

        $_COOKIE['user'] = 'session-owner';
        $this->setBridgedSessionNick('session-owner');

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger, $this->makeSession());

        $this->callPrivate($login, 'log_out', [true]);

        $this->assertSame(['session-owner'], RecordingFsUser::$lookups);
        $this->assertSame(['session-owner'], RecordingAccount::$rotations, 'El logout autenticado rota el log_key');
        $this->assertSame(['session-owner'], RecordingAccount::$saves);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function revokeCurrentLegacyLoginIgnoresTheCookieWithoutASession(): void
    {
        $this->installLegacyUserDouble();

        $_COOKIE['user'] = 'named-account';

        $bridge = new \FSFramework\Security\LegacyAuthBridge($this->makeSession());
        $bridge->revokeCurrentLegacyLogin();

        $this->assertSame([], RecordingFsUser::$lookups);
        $this->assertSame([], RecordingAccount::$rotations);
        $this->assertSame(0, RecordingFsUser::$instances);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function revokeCurrentLegacyLoginUsesTheSessionNickname(): void
    {
        $this->installLegacyUserDouble();

        $_COOKIE['user'] = 'someone-else';

        $session = $this->makeSession();
        $session->set('user_nick', 'session-owner');

        $bridge = new \FSFramework\Security\LegacyAuthBridge($session);
        $bridge->revokeCurrentLegacyLogin();

        $this->assertSame(['session-owner'], RecordingFsUser::$lookups);
        $this->assertSame(['session-owner'], RecordingAccount::$rotations);
    }

    // =====================================================================
    // RMR-02: the remember-me feature is retained
    // =====================================================================

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function validRememberMeTripleStillRestoresTheSession(): void
    {
        $this->installLegacyUserDouble();

        $account = $this->makeCookieAccount('live-logkey');
        $signature = CookieSigner::signRememberMe($account->nick, 'live-logkey');

        $logger = $this->makeLogger();
        $login = $this->makeLogin($logger);
        $controllerUser = (object) ['logged_on' => false];

        $restored = $this->callPrivate($login, 'applyValidCookieLogin', [
            $account,
            'live-logkey',
            $signature,
            &$controllerUser,
        ]);

        $this->assertTrue($restored, 'El mecanismo remember-me se mantiene');
        $this->assertSame($account, $controllerUser);
        $this->assertSame([], RecordingAccount::$rotations, 'La restauración válida no revoca nada');
        $this->assertSame([], $logger->messages);
    }

    // =====================================================================
    // RMR-01: there is no second copy of the rule
    // =====================================================================

    /**
     * RMR-01 requires the duplicate fallback in
     * `fs_login::revokeCurrentRememberMeToken()` to be *deleted* rather than
     * patched, so the rule cannot drift again. The method is unreachable in this
     * process whenever `SessionManager` exists (it delegates to the bridge), so
     * the property is asserted on its source, which is what the requirement
     * states.
     */
    #[Test]
    public function revokeCurrentRememberMeTokenHasNoCookieBasedResolution(): void
    {
        $body = $this->methodSource(\fs_login::class, 'revokeCurrentRememberMeToken');

        $this->assertStringNotContainsString('INPUT_COOKIE', $body);
        $this->assertStringNotContainsString('$_COOKIE', $body);
        $this->assertStringContainsString('revokeCurrentLegacyLogin', $body, 'Debe delegar en el puente');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Installs the legacy global `fs_user` double. See the file docblock.
     */
    private function installLegacyUserDouble(): void
    {
        $this->assertFalse(
            class_exists('fs_user', false),
            'fs_user ya está cargado: el doble no puede instalarse en este proceso'
        );

        // Through a variable so the alias is a runtime fact and not a symbol
        // declaration (same shape as tests/Core/PluginSchemaSynchronizerTest).
        $double = RecordingFsUser::class;
        $this->assertTrue(class_alias($double, 'fs_user'), 'No se pudo instalar el doble de fs_user');
        $this->assertSame(
            (new \ReflectionClass(RecordingFsUser::class))->getFileName(),
            (new \ReflectionClass('fs_user'))->getFileName(),
            'El nombre fs_user debe resolver al doble, no a model/core/fs_user.php'
        );
    }

    private function setBridgedSessionNick(string $nick): void
    {
        \FSFramework\Security\SessionManager::getInstance()->getSymfonySession()->set('user_nick', $nick);
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        return $session;
    }

    private function makeLogin(object $logger, ?Session $session = null): \fs_login
    {
        $login = (new \ReflectionClass(\fs_login::class))->newInstanceWithoutConstructor();

        $this->setPrivate($login, 'ban_message', 'banned');
        $this->setPrivate($login, 'core_log', $logger);
        $this->setPrivate($login, 'cache', new class {
            public function clean(): void
            {
            }
        });
        $this->setPrivate($login, 'ip_filter', new class {
            public function in_white_list(string $ip): bool
            {
                return true;
            }

            public function clear(): void
            {
            }

            public function is_banned(string $ip): bool
            {
                return false;
            }

            public function set_attempt(string $ip): void
            {
            }
        });
        $this->setPrivate($login, 'user_model', new class {
            public function get(string $nick): mixed
            {
                return null;
            }

            public function clean_cache(bool $force = false): void
            {
            }
        });
        $this->setPrivate($login, 'session', $session ?? $this->makeSession());

        return $login;
    }

    private function makeLogoutRecordingLogin(object $logger): RecordingLogoutLogin
    {
        $login = new RecordingLogoutLogin();
        $this->setPrivate($login, 'core_log', $logger);
        $this->setPrivate($login, 'session', $this->makeSession());

        return $login;
    }

    private function makeLogger(): object
    {
        return new class {
            /** @var list<string> */
            public array $errors = [];
            /** @var list<string> */
            public array $messages = [];
            /** @var list<string> */
            public array $saved = [];
            /** @var list<mixed> */
            public array $userNicks = [];

            public function new_error(string $message): void
            {
                $this->errors[] = $message;
            }

            public function new_message(string $message): void
            {
                $this->messages[] = $message;
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
                $this->saved[] = $message;
            }

            public function set_user_nick($user): void
            {
                $this->userNicks[] = $user;
            }
        };
    }

    /**
     * The account the remember-me handler believes the request names, as it
     * would come back from `fs_user::get()`.
     */
    private function makeStoredAccount(string $nick, string $lastIp = '203.0.113.7'): object
    {
        return new class($nick, $lastIp) {
            public string $nick;
            public string $last_ip;
            public string $log_key = 'stored-log-key';
            public bool $enabled = true;

            public function __construct(string $nick, string $lastIp)
            {
                $this->nick = $nick;
                $this->last_ip = $lastIp;
            }
        };
    }

    private function makeCookieAccount(string $logKey): object
    {
        return new class($logKey) {
            public string $nick = 'remembered-user';
            public string $email = 'remembered@example.com';
            public bool $admin = false;
            public bool $enabled = true;
            public string $log_key;
            public bool $logged_on = false;

            public function __construct(string $logKey)
            {
                $this->log_key = $logKey;
            }

            public function update_login(): void
            {
                // No-op: update_login() escribe en la base de datos.
            }
        };
    }

    private function callPrivate(object $instance, string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod(\fs_login::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($instance, $args);
    }

    private function setPrivate(object $instance, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty(\fs_login::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function methodSource(string $class, string $methodName): string
    {
        $method = new ReflectionMethod($class, $methodName);
        $file = new \SplFileObject((string) $method->getFileName());
        $file->seek($method->getStartLine() - 1);

        $source = '';
        while ($file->key() < $method->getEndLine()) {
            $source .= $file->current();
            $file->next();
        }

        return $source;
    }
}

/**
 * Stand-in for the legacy global `fs_user`, installed under that exact name by
 * `installLegacyUserDouble()`. It records every account lookup and every
 * mutation the revocation path would perform.
 */
final class RecordingFsUser
{
    public static int $instances = 0;

    /** @var list<string> */
    public static array $lookups = [];

    public static bool $accountExists = true;

    public function __construct()
    {
        self::$instances++;
    }

    public function get(string $nick): ?RecordingAccount
    {
        self::$lookups[] = $nick;

        return self::$accountExists ? new RecordingAccount($nick) : null;
    }
}

final class RecordingAccount
{
    /** @var list<string> */
    public static array $rotations = [];

    /** @var list<string> */
    public static array $saves = [];

    public function __construct(public string $nick)
    {
    }

    public function rotate_logkey(): void
    {
        self::$rotations[] = $this->nick;
    }

    public function save(): bool
    {
        self::$saves[] = $this->nick;

        return true;
    }
}

/**
 * `fs_login` double that records the remember-me flag of every `log_out()`
 * call. It skips the parent constructor (which opens the database) and never
 * runs the parent `log_out()`, so the caller-scoped cleanup can be observed
 * without a database or a header-level assertion.
 */
final class RecordingLogoutLogin extends \fs_login
{
    /** @var list<bool> */
    public array $logoutCalls = [];

    public function __construct()
    {
        // Intencionado: el constructor del padre abre la base de datos.
    }

    public function log_out($rmuser = FALSE)
    {
        $this->logoutCalls[] = (bool) $rmuser;
    }
}
