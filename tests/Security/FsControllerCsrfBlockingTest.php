<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Cache\CacheManager;
use FSFramework\Security\CsrfManager;
use FSFramework\Security\LoginThrottle;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_auth_request.php';
require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/login.php';

/**
 * Verifies that invalid CSRF tokens block private_core execution in strict mode,
 * and that a credential submission records exactly one throttle attempt.
 */
final class FsControllerCsrfBlockingTest extends TestCase
{
    private const THROTTLE_NICK = 'csrf_throttle_subject';

    private bool $hadCsrfSoft = false;
    private bool $csrfSoftValue = false;

    private CountingIpFilter $ipFilter;

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        CacheManager::reset();
        LoginThrottle::clear(self::THROTTLE_NICK);
        $_COOKIE = [];
        $_SESSION = [];

        $this->ipFilter = new CountingIpFilter();

        if (defined('FS_CSRF_SOFT')) {
            $this->hadCsrfSoft = true;
            $this->csrfSoftValue = FS_CSRF_SOFT;
        }

        $this->resetCsrfState();
    }

    protected function tearDown(): void
    {
        $this->resetCsrfState();
        LoginThrottle::clear(self::THROTTLE_NICK);
        CacheManager::reset();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testInvalidCsrfTokenBlocksPrivateCoreInStrictMode(): void
    {
        if ($this->hadCsrfSoft && $this->csrfSoftValue) {
            $this->markTestSkipped('FS_CSRF_SOFT is enabled in config.php');
        }

        $controller = new class() extends \fs_controller {
            public function __construct()
            {
                // Skip full fs_controller bootstrap — test validateCsrf + guard only.
            }

            public function runCsrfGuard(): bool
            {
                $this->request = Request::create('/index.php?page=test', 'POST', [
                    CsrfManager::FIELD_NAME => 'invalid-token-value',
                ]);
                $this->class_name = 'test_controller';
                $this->core_log = new \fs_core_log('test_controller');

                return $this->validateCsrfPublic();
            }

            protected function validateCsrfPublic(): bool
            {
                $ref = new \ReflectionMethod(\fs_controller::class, 'validateCsrf');
                $ref->setAccessible(true);

                return (bool) $ref->invoke($this);
            }
        };

        $this->assertFalse($controller->runCsrfGuard());
        $this->assertFalse($controller->isCsrfValid());
    }

    public function testRequireCsrfDoesNotRevalidateConsumedToken(): void
    {
        $token = CsrfManager::generateToken();

        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => $token,
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $validate = new \ReflectionMethod(\fs_controller::class, 'validateCsrf');
        $validate->setAccessible(true);
        $this->assertTrue($validate->invoke($controller));

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);
        $this->assertTrue($require->invoke($controller));
    }

    /**
     * `requireCsrf()` used to defer to `isCsrfValid()`, whose backing flag
     * starts at TRUE: a POST handler that ran before any validation (public_core,
     * an AJAX endpoint) skipped CSRF entirely. Without prior validation the
     * guard must validate now.
     */
    public function testRequireCsrfFailsClosedWithoutPriorValidation(): void
    {
        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => 'invalid-token-value',
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);

        $this->assertFalse($require->invoke($controller));
        $this->assertFalse($controller->isCsrfValid());
    }

    public function testRequireCsrfAcceptsValidTokenWithoutPriorValidation(): void
    {
        $token = CsrfManager::generateToken();

        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => $token,
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $require = new \ReflectionMethod(\fs_controller::class, 'requireCsrf');
        $require->setAccessible(true);

        $this->assertTrue($require->invoke($controller));
        $this->assertTrue($controller->isCsrfValid());
    }

    /**
     * Login-CSRF: a credential POST with an invalid token must not reach the
     * login machinery. `login_tools` is intentionally left null here, so any
     * execution past the CSRF guard raises an Error instead of returning false.
     */
    public function testLoginPostWithInvalidTokenDoesNotRunLogin(): void
    {
        $controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'request', Request::create('/', 'POST', [
            'user' => 'admin',
            'password' => 'secret',
            CsrfManager::FIELD_NAME => 'invalid-token-value',
        ]));
        $this->setProperty($controller, 'core_log', new \fs_core_log('test'));
        $this->setProperty($controller, 'class_name', 'test');

        $logIn = new \ReflectionMethod(\fs_controller::class, 'log_in');
        $logIn->setAccessible(true);

        $this->assertFalse($logIn->invoke($controller));
    }

    /**
     * LC-06 — the throttle must reflect real submissions.
     *
     * The IP ban counter (`fs_ip_filter::set_attempt()`, base/fs_login.php:140)
     * and `LoginThrottle::recordFailure()` used to be incremented once per
     * credential evaluation, and a submission was evaluated twice: once by
     * `fs_controller::log_in()` and once by the credential branch of
     * `login::process_login_logic()`. After the guard they are incremented once
     * per submission, so the lockout needs 6 real failed submissions instead of
     * 3. That is the change's stated intent, not a regression.
     *
     * Harness note (what this test can and cannot observe): `fs_login::log_in()`
     * reads the credentials with `filter_input(INPUT_POST, ...)`, which is blind
     * under the CLI SAPI — the runner has no POST body, so the real method reads
     * nothing even with `$_POST` populated. The delivery is therefore
     * reproduced by `CredentialDeliverySpy` (the two lines of
     * base/fs_login.php:139-141), while the evaluation it delegates to
     * (`fs_login::log_in_user()`) and the `LoginThrottle` counter are the real
     * ones. The second evaluation site is modelled the same way by
     * `CredentialUserDouble`, which reproduces the side effects of
     * `fs_user::login()` -> `fs_login::log_in()` (model/core/fs_user.php:494).
     */
    public function testInvalidTokenSubmissionRecordsNoThrottleAttempt(): void
    {
        $harness = $this->makeThrottleHarness(Request::create('/index.php?page=login', 'POST', [
            'user' => self::THROTTLE_NICK,
            'password' => 'wrong',
            CsrfManager::FIELD_NAME => 'not-a-valid-token',
        ]));

        $harness->runRequest();

        $this->assertSame(0, $harness->user->loginCalls, 'the credential branch must not run');
        $this->assertSame(
            0,
            $this->ipFilter->attempts,
            'a CSRF-rejected submission must not count as a credential attempt'
        );
        $this->assertSame(0, LoginThrottle::getAttemptCount(self::THROTTLE_NICK));
    }

    /**
     * LC-06 scenario "token válido y credenciales incorrectas evalúan una sola
     * vez": exactly one throttle attempt per submission (it used to be two).
     *
     * Note on the invalid-token shape above: the delta's scenario also asks for
     * "exactly one set_attempt()" there. That clause is unreachable with the
     * guard the same delta mandates — `set_attempt()` lives inside
     * `fs_login::log_in()` (base/fs_login.php:139-141), which the CSRF verdict
     * now prevents the request from reaching at all — and reproducing it would
     * need a new, unlisted write on the rejection path that would let a
     * cross-site form POST ban an arbitrary victim's IP. The requirement body
     * only mandates the valid-token arithmetic ("una submission fallida con
     * token válido MUST producir exactamente un intento de throttle"), which is
     * what is asserted here; the rejection path is asserted to be inert.
     */
    public function testValidTokenWrongPasswordSubmissionRecordsExactlyOneThrottleAttempt(): void
    {
        $harness = $this->makeThrottleHarness(Request::create('/index.php?page=login', 'POST', [
            'user' => self::THROTTLE_NICK,
            'password' => 'wrong',
            CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
        ]));

        $harness->runRequest();

        $this->assertSame(
            1,
            $harness->loginTools->logInCalls,
            'the credential POST must reach fs_login exactly once'
        );
        $this->assertSame(
            1,
            $this->ipFilter->attempts,
            'exactly one set_attempt() per submission (it used to be two)'
        );
        $this->assertSame(
            1,
            LoginThrottle::getAttemptCount(self::THROTTLE_NICK),
            'exactly one throttle attempt per submission (it used to be two)'
        );
    }

    public function testTheLockoutNowNeedsSixRealFailedSubmissionsInsteadOfThree(): void
    {
        for ($submission = 1; $submission <= 5; $submission++) {
            $this->makeThrottleHarness($this->validCredentialPost())->runRequest();
        }

        $this->assertSame(5, LoginThrottle::getAttemptCount(self::THROTTLE_NICK));
        $this->assertFalse(
            LoginThrottle::isThrottled(self::THROTTLE_NICK),
            'five real failed submissions must not lock the account out any more'
        );

        $this->makeThrottleHarness($this->validCredentialPost())->runRequest();

        $this->assertSame(6, LoginThrottle::getAttemptCount(self::THROTTLE_NICK));
        $this->assertTrue(
            LoginThrottle::isThrottled(self::THROTTLE_NICK),
            'the lockout now fires on the sixth real failed submission'
        );
    }

    private function validCredentialPost(): Request
    {
        return Request::create('/index.php?page=login', 'POST', [
            'user' => self::THROTTLE_NICK,
            'password' => 'wrong',
            CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
        ]);
    }

    private function makeThrottleHarness(Request $request): LoginThrottleHarness
    {
        $coreLog = new CsrfBlockingCoreLog();
        $user = new CredentialUserDouble($this->ipFilter, $coreLog);
        $loginTools = new CredentialDeliverySpy($this->makeLoginTools($coreLog), $this->ipFilter, $request);

        $controller = new class($request, $coreLog, $loginTools, $user) extends \login {
            public function __construct(
                Request $request,
                CsrfBlockingCoreLog $coreLog,
                CredentialDeliverySpy $loginTools,
                CredentialUserDouble $user
            ) {
                $this->request = $request;
                $this->core_log = $coreLog;
                $this->class_name = 'login';
                $this->user = $user;
                $this->db = new LoginThrottleDbStub();
                $this->template = '';

                $loginToolsProperty = new \ReflectionProperty(\fs_controller::class, 'login_tools');
                $loginToolsProperty->setValue($this, $loginTools);
            }

            public function runLogIn(): bool
            {
                $logIn = new \ReflectionMethod(\fs_controller::class, 'log_in');
                $logIn->setAccessible(true);

                return (bool) $logIn->invoke($this);
            }

            public function runPublicCore(): void
            {
                $this->public_core();
            }
        };

        return new LoginThrottleHarness($this->ipFilter, $loginTools, $user, $controller);
    }

    /**
     * The real fs_login, with every collaborator that needs a database, a
     * session store or a file replaced by a double. `log_in_user()` — the
     * credential evaluation — is the real implementation.
     */
    private function makeLoginTools(CsrfBlockingCoreLog $coreLog): \fs_login
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $login = (new \ReflectionClass(\fs_login::class))->newInstanceWithoutConstructor();

        $this->setProperty($login, 'ban_message', 'banned');
        $this->setProperty($login, 'core_log', $coreLog);
        $this->setProperty($login, 'cache', new class {
            public function clean(): void
            {
            }
        });
        $this->setProperty($login, 'ip_filter', $this->ipFilter);
        $this->setProperty($login, 'user_model', new class {
            public function get(string $nick)
            {
                return false;
            }

            public function clean_cache(bool $force = false): void
            {
            }
        });
        $this->setProperty($login, 'session', $session);

        return $login;
    }

    private function setProperty(object $instance, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function resetCsrfState(): void
    {
        $ref = new \ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}

/**
 * Spies on the IP ban counter write (base/fs_ip_filter.php:116) without
 * touching tmp/<FS_TMP_NAME>ip.log.
 */
final class CountingIpFilter extends \fs_ip_filter
{
    public int $attempts = 0;

    public function __construct()
    {
    }

    public function is_banned($ip)
    {
        return false;
    }

    public function set_attempt($ip)
    {
        $this->attempts++;
    }

    public function in_white_list($ip)
    {
        return true;
    }

    public function clear()
    {
    }
}

/**
 * The login form's error list, isolated from fs_core_log's shared static state:
 * every accessor the controllers use is overridden.
 */
final class CsrfBlockingCoreLog extends \fs_core_log
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct(private string $name = 'login')
    {
    }

    public function controller_name()
    {
        return $this->name;
    }

    public function new_error($msg, $context = [])
    {
        $this->errors[] = (string) $msg;
    }

    public function new_message($msg, $context = [])
    {
    }

    public function new_advice($msg, $context = [])
    {
    }

    public function save($msg, $type = 'error', $alert = FALSE, $context = [])
    {
    }

    public function set_user_nick($nick)
    {
    }

    public function user_nick()
    {
        return '';
    }
}

/**
 * The controller's login tools. Only the two lines of fs_login::log_in() that
 * cannot run under the CLI SAPI (the filter_input() reads) are reproduced here:
 * the credential path counts the attempt (base/fs_login.php:140) and then
 * delegates to the real evaluation, fs_login::log_in_user().
 */
final class CredentialDeliverySpy
{
    public int $logInCalls = 0;

    public function __construct(
        private \fs_login $login,
        private CountingIpFilter $ipFilter,
        private ?Request $request = null
    ) {
    }

    public function log_in(\fs_user $user): bool
    {
        $this->logInCalls++;

        if ($this->request === null) {
            return false;
        }

        $method = $this->request->getMethod();
        $post = $this->request->request->all();

        if (!\fs_auth_request::hasCredentialSubmission($method, $post)) {
            return false;
        }

        $nick = trim((string) ($post['nick'] ?? $post['user'] ?? ''));
        $password = trim((string) ($post['password'] ?? ''));
        $ip = fs_get_ip();

        $this->ipFilter->set_attempt($ip);

        $logInUser = new \ReflectionMethod(\fs_login::class, 'log_in_user');
        $logInUser->setAccessible(true);
        $args = [&$user, $nick, $password, $ip];

        return (bool) $logInUser->invokeArgs($this->login, $args);
    }

    public function log_out(bool $rmuser = false): void
    {
    }
}

/**
 * The second credential-evaluation site: `fs_user::login()` delegates to
 * `fs_login::log_in()` (model/core/fs_user.php:494-504), which is where the
 * second attempt used to be counted. Under the CLI SAPI that delegation cannot
 * read the POST (filter_input(INPUT_POST) is blind), so the double reproduces
 * its observable side effects: one set_attempt() and one recordFailure().
 */
final class CredentialUserDouble extends \fs_user
{
    public int $loginCalls = 0;

    public function __construct(private CountingIpFilter $ipFilter, private CsrfBlockingCoreLog $coreLog)
    {
        $this->logged_on = false;
    }

    public function login(string $nick, string $password): bool
    {
        $this->loginCalls++;
        $this->ipFilter->set_attempt(fs_get_ip());
        LoginThrottle::recordFailure($nick);
        $this->coreLog->new_error(LoginThrottle::GENERIC_ERROR);

        return false;
    }

    public function get_menu($reload = false)
    {
        return [];
    }
}

/**
 * StealthMode reads its settings through fs_db2 and gives up before querying
 * when the connection is unavailable; that is the "stealth disabled" case this
 * test needs.
 */
final class LoginThrottleDbStub extends \fs_db2
{
    public function __construct()
    {
    }

    public function connected()
    {
        return false;
    }

    public function connect()
    {
        return false;
    }
}

/**
 * One login request: the controller constructor's log_in() first, then
 * public_core() when the visitor is still anonymous.
 */
final class LoginThrottleHarness
{
    public function __construct(
        public readonly CountingIpFilter $ipFilter,
        public readonly CredentialDeliverySpy $loginTools,
        public readonly CredentialUserDouble $user,
        private readonly object $controller
    ) {
    }

    public function runRequest(): void
    {
        if (!$this->controller->runLogIn()) {
            $this->controller->runPublicCore();
        }
    }
}
