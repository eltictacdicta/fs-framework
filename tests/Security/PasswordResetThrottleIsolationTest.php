<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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
 */

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use FSFramework\Security\SignedUrlService;
use FSFramework\Translation\FSTranslator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Controller\Concerns\ExtractsMethodBody;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/password_reset.php';

/**
 * LTI-01…LTI-05 (VULN-0006) — lockout state is a security control.
 *
 * The anonymous password-reset form used to write the same per-IP counter that
 * gates authentication, before validating anything and with no CSRF token, so
 * a handful of empty POSTs locked a whole network out of the ERP and out of
 * the recovery form itself.
 *
 * What this test observes in-process, and what it deliberately does not:
 *  - It drives the real `password_reset` controller (constructor bypassed, the
 *    way tests/Controller/LoginPublicCoreCsrfTest.php does) against the real
 *    `fs_ip_filter`, so the counter arithmetic is the production arithmetic:
 *    the buckets are read back from disk, not from a spy.
 *  - It observes the authentication side through the real `fs_login::log_in()`
 *    ban check. Under the CLI SAPI `filter_input(INPUT_POST, …)` returns null
 *    (verified on the project's PHP 8.3 container), so the credential
 *    comparison itself is unreachable in any in-process test; the ban branch
 *    is, and it is the branch that decides whether the comparison runs at all.
 *    The source pin in `theBanMessageIsGuardedByTheAuthenticationBucket()`
 *    closes that gap: the ban message is emitted iff `is_banned()` on the
 *    authentication bucket is true, and the comparison (`log_in_user()`) sits
 *    after it.
 *  - It does not install an `fs_user` double: `fs_user` is already loaded in
 *    this shared process, so the "real attempt" case resolves a nickname that
 *    does not exist and therefore mints no token and sends no mail.
 */
final class PasswordResetThrottleIsolationTest extends TestCase
{
    use ExtractsMethodBody;

    private const CSRF_MISSING_ERROR = 'Sesión expirada o token de seguridad faltante. Por favor, recarga la página.';

    private const CSRF_INVALID_ERROR = 'Token de seguridad inválido. Por favor, recarga la página.';

    /** Documentation-range address, never routable. */
    private const CLIENT_IP = '198.51.100.23';

    private const BAN_MESSAGE_MARKER = 'Tu IP ha sido baneada';

    private ResetFlowCoreLog $coreLog;

    private ResetFlowCoreLog $loginCoreLog;

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $this->resetCsrfManager();
        $this->removeBuckets();

        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = ['page' => 'password_reset'];
        $_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page=password_reset';

        $this->coreLog = new ResetFlowCoreLog('password_reset');
        $this->loginCoreLog = new ResetFlowCoreLog('login');
    }

    protected function tearDown(): void
    {
        $this->removeBuckets();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        parent::tearDown();
    }

    // =====================================================================
    // LTI-01 — one owner per lockout bucket
    // =====================================================================

    /**
     * LTI-01 scenario 1: an anonymous reset burst must not lock out
     * authentication, i.e. the password comparison must still be reached.
     */
    #[Test]
    public function sixAnonymousEmptyResetPostsDoNotLockOutAuthentication(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->driveResetRequest($this->resetRequest(['nick_or_email' => '']));
        }

        $this->assertSame(
            0,
            $this->attemptsIn($this->authenticationBucket()),
            'the authentication bucket must not be written by reset requests'
        );
        $this->assertFalse(
            (new \fs_ip_filter())->is_banned(self::CLIENT_IP),
            'six anonymous reset requests must not ban the address for authentication'
        );
        $this->assertSame(
            [],
            $this->authenticationBanErrors(),
            'the credential POST must not be answered with the ban message'
        );
    }

    /**
     * LTI-01 scenario 5: exhausting the reset quota has no authentication
     * consequence.
     */
    #[Test]
    public function exhaustingTheResetQuotaLeavesAuthenticationAlone(): void
    {
        $this->fillBucket('password_reset');

        $this->assertTrue((new \fs_ip_filter('password_reset'))->is_banned(self::CLIENT_IP));
        $this->assertFalse((new \fs_ip_filter())->is_banned(self::CLIENT_IP));
        $this->assertSame([], $this->authenticationBanErrors());
    }

    /**
     * LTI-01 scenario 3 and 4: the reset controller owns its bucket and the
     * authentication construction site keeps the default one.
     */
    #[Test]
    public function theResetControllerOwnsItsBucket(): void
    {
        $this->driveResetRequest($this->resetRequest(['nick_or_email' => 'nobody@example.test']));

        $this->assertSame(1, $this->attemptsIn($this->resetBucket()));
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
        $this->assertFileDoesNotExist(
            $this->authenticationBucket(),
            'a reset attempt must not create the authentication bucket'
        );

        $publicCore = $this->normalizedMethodBody('/controller/password_reset.php', 'public_core');
        $this->assertStringContainsString(
            "new fs_ip_filter('password_reset')",
            $publicCore,
            'the reset controller must construct its filter with its own namespace'
        );

        $loginLogIn = $this->normalizedMethodBody('/base/fs_login.php', 'log_in');
        $this->assertStringNotContainsString(
            "new fs_ip_filter(",
            $loginLogIn,
            'authentication must keep the default bucket'
        );
    }

    /**
     * LTI-01: `set_attempt()` keeps exactly three production call sites, all
     * writing the bucket owned by their feature.
     */
    #[Test]
    public function setAttemptKeepsExactlyThreeProductionCallSites(): void
    {
        $callSites = [];

        foreach (['base', 'controller', 'model', 'src'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(FS_FOLDER . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $count = substr_count($source, '->set_attempt(');
                if ($count > 0) {
                    $callSites[] = $directory . '/' . $file->getFilename() . ':' . $count;
                }
            }
        }

        sort($callSites);

        $this->assertSame(
            [
                'base/fs_login.php:1',
                'controller/password_reset.php:2',
            ],
            $callSites,
            'the authentication bucket is written by credential submissions only; a new writer must bring its own namespace'
        );
    }

    // =====================================================================
    // LTI-03 — validation precedes counting
    // =====================================================================

    /**
     * LTI-03 scenario 1: an empty submission increments no counter in either
     * bucket and renders the validation message.
     */
    #[Test]
    public function anEmptyResetSubmissionIncrementsNoCounter(): void
    {
        $controller = $this->driveResetRequest($this->resetRequest(['nick_or_email' => '']));

        $this->assertSame(
            [FSTranslator::trans('password-reset-enter-nick-or-email')],
            $this->coreLog->errors
        );
        $this->assertFalse($controller->email_sent, 'an empty submission must not report an email as sent');
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
        $this->assertSame(0, $this->attemptsIn($this->resetBucket()));
    }

    /**
     * LTI-03 scenario 2: a real attempt records exactly one counter, in the
     * reset bucket.
     */
    #[Test]
    public function aRealResetAttemptCountsOnceInItsOwnBucket(): void
    {
        $controller = $this->driveResetRequest(
            $this->resetRequest(['nick_or_email' => 'nobody@example.test'])
        );

        $this->assertSame(1, $this->attemptsIn($this->resetBucket()), 'exactly one attempt, in the reset bucket');
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
        $this->assertTrue($controller->email_sent, 'a valid submission proceeds as today');
        $this->assertSame([FSTranslator::trans('password-reset-email-sent')], $this->coreLog->messages);
    }

    /**
     * LTI-03 is an ordering requirement, so the order is pinned in the source
     * as well: the empty check must precede `set_attempt()`.
     */
    #[Test]
    public function theEmptyCheckPrecedesSetAttemptInProcessRequest(): void
    {
        $body = $this->normalizedMethodBody('/controller/password_reset.php', 'process_request');

        $emptyCheck = strpos($body, 'empty($nick_or_email)');
        $counting = strpos($body, 'set_attempt($ip)');

        $this->assertNotFalse($emptyCheck, 'the empty-nick_or_email check must stay in process_request()');
        $this->assertNotFalse($counting, 'process_request() must still count its real attempts');
        $this->assertLessThan($counting, $emptyCheck, 'a submission that is not an attempt must not be counted');
    }

    // =====================================================================
    // LTI-04 — the reset flow requires a CSRF token
    // =====================================================================

    /**
     * LTI-04 scenario 1: a token-less reset request is refused without side
     * effects.
     */
    #[Test]
    public function aTokenLessResetRequestIsRefusedWithoutSideEffects(): void
    {
        $controller = $this->driveResetRequest(
            $this->resetRequest(['nick_or_email' => 'nobody@example.test'])
        );

        $this->assertSame([self::CSRF_MISSING_ERROR], $this->coreLog->errors);
        $this->assertSame([], $this->coreLog->messages, 'no lookup, no mint and no mail may be reported');
        $this->assertFalse($controller->email_sent);
        $this->assertSame(0, $this->attemptsIn($this->resetBucket()));
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
    }

    /**
     * LTI-04 scenario 2: an invalid token is refused identically.
     */
    #[Test]
    public function anInvalidTokenIsRefusedIdentically(): void
    {
        $controller = $this->driveResetRequest(
            $this->resetRequest([
                'nick_or_email' => 'nobody@example.test',
                CsrfManager::FIELD_NAME => 'not-a-valid-token',
            ])
        );

        $this->assertSame([self::CSRF_INVALID_ERROR], $this->coreLog->errors);
        $this->assertSame([], $this->coreLog->messages);
        $this->assertFalse($controller->email_sent);
        $this->assertSame(0, $this->attemptsIn($this->resetBucket()));
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
    }

    /**
     * LTI-04 scenario 3: a valid token preserves today's behaviour.
     */
    #[Test]
    public function aValidTokenPreservesTodaysBehaviour(): void
    {
        $controller = $this->driveResetRequest(
            $this->resetRequest([
                'nick_or_email' => 'nobody@example.test',
                CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
            ])
        );

        $this->assertSame([], $this->coreLog->errors);
        $this->assertTrue($controller->email_sent);
        $this->assertSame([FSTranslator::trans('password-reset-email-sent')], $this->coreLog->messages);
    }

    /**
     * LTI-04 scenario 4: reset completion validates the token too.
     *
     * The URL is properly signed, so the signature check cannot be what stops
     * the request: with a valid token the flow would reach the password
     * checks. The CSRF error is therefore the only acceptable outcome, and it
     * proves the guard runs before the reset work.
     */
    #[Test]
    public function theResetCompletionBranchValidatesTheToken(): void
    {
        $controller = $this->driveResetRequest(
            $this->resetPost($this->completionFields()),
            $this->signedResetUrl(),
            [CsrfManager::FIELD_NAME => 'not-a-valid-token']
        );

        $this->assertSame(
            [self::CSRF_INVALID_ERROR],
            $this->coreLog->errors,
            'the CSRF verdict must be reached before the signed-url and password checks'
        );
        $this->assertFalse($controller->password_changed);
        $this->assertSame(0, $this->attemptsIn($this->resetBucket()));
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
    }

    /**
     * LTI-01: `process_reset()` keeps counting a genuinely failed token
     * validation, now in the reset bucket.
     */
    #[Test]
    public function aFailedResetTokenValidationCountsInTheResetBucket(): void
    {
        $this->driveResetRequest(
            $this->resetPost($this->completionFields()),
            $this->signedResetUrl(),
            [CsrfManager::FIELD_NAME => CsrfManager::generateToken()]
        );

        $this->assertSame(
            [FSTranslator::trans('password-reset-invalid-or-expired')],
            $this->coreLog->errors
        );
        $this->assertSame(1, $this->attemptsIn($this->resetBucket()));
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
    }

    /**
     * LTI-04 is an ordering requirement: the CSRF verdict must precede every
     * side effect in both entry points.
     */
    #[Test]
    public function theCsrfVerdictPrecedesEverySideEffect(): void
    {
        $request = $this->normalizedMethodBody('/controller/password_reset.php', 'process_request');
        $reset = $this->normalizedMethodBody('/controller/password_reset.php', 'process_reset');

        $guardInRequest = strpos($request, 'requireCsrf()');
        $guardInReset = strpos($reset, 'requireCsrf()');

        $this->assertNotFalse($guardInRequest, 'process_request() must require a CSRF token');
        $this->assertNotFalse($guardInReset, 'process_reset() must require a CSRF token');

        $this->assertLessThan(strpos($request, 'set_attempt($ip)'), $guardInRequest);
        $this->assertLessThan(strpos($request, 'new fs_user()'), $guardInRequest);

        $this->assertLessThan(strpos($reset, 'set_attempt($ip)'), $guardInReset);
        $this->assertLessThan(strpos($reset, 'new fs_user()'), $guardInReset);
        $this->assertLessThan(strpos($reset, 'validate_signed_reset_url()'), $guardInReset);
    }

    // =====================================================================
    // LTI-05 — the reset flow keeps its own throttle, unchanged
    // =====================================================================

    /**
     * LTI-05 scenario 1: the reset throttle still fires on its own bucket, and
     * authentication from the same address is unaffected.
     */
    #[Test]
    public function theResetThrottleStillFiresOnItsOwnBucket(): void
    {
        $this->fillBucket('password_reset');

        $controller = $this->driveResetRequest(
            $this->resetRequest([
                'nick_or_email' => 'nobody@example.test',
                CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
            ])
        );

        $this->assertSame(
            [FSTranslator::trans('password-reset-too-many-attempts')],
            $this->coreLog->errors
        );
        $this->assertFalse($controller->email_sent);
        $this->assertSame(0, $this->attemptsIn($this->authenticationBucket()));
        $this->assertFalse((new \fs_ip_filter())->is_banned(self::CLIENT_IP));
    }

    /**
     * LTI-05 scenario 2: the authentication lockout keeps its full strength —
     * same file, same MAX_ATTEMPTS, same sliding window.
     */
    #[Test]
    public function theAuthenticationLockoutKeepsItsStrength(): void
    {
        $authentication = new \fs_ip_filter();

        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $authentication->set_attempt(self::CLIENT_IP);
        }

        $this->assertSame(
            \fs_ip_filter::MAX_ATTEMPTS + 1,
            $this->attemptsIn($this->authenticationBucket()),
            'the authentication bucket keeps the documented threshold and window'
        );
        $this->assertTrue((new \fs_ip_filter())->is_banned(self::CLIENT_IP));
        $this->assertFalse(
            (new \fs_ip_filter('password_reset'))->is_banned(self::CLIENT_IP),
            'an authentication ban must not be visible in the reset bucket'
        );
    }

    /**
     * LTI-05 scenario 3: no public contract changed. The template already
     * shipped `{{ csrf_field() }}` in both forms (design D12), so the fix is
     * controller-side only and must not add a second token field.
     */
    #[Test]
    public function theResetFlowIntroducesNoNewTemplateOrTranslationContract(): void
    {
        $template = (string) file_get_contents(FS_FOLDER . '/themes/AdminLTE/view/password_reset.html.twig');

        $this->assertSame(
            2,
            substr_count($template, 'csrf_field()'),
            'the request and the completion forms already carried the token; no duplicate field may be added'
        );

        $source = (string) file_get_contents(FS_FOLDER . '/controller/password_reset.php');
        foreach ([
            'password-reset-too-many-attempts',
            'password-reset-enter-nick-or-email',
            'password-reset-email-sent',
            'password-reset-invalid-or-expired',
        ] as $key) {
            $this->assertStringContainsString($key, $source, 'the same message keys must be rendered as today');
        }
    }

    // =====================================================================
    // The ban message is the observable that LTI-01 scenario 1 names
    // =====================================================================

    /**
     * The ban message is emitted iff the authentication bucket is banned, and
     * the credential comparison sits after it. Together with the behavioural
     * assertions above this is what "the password comparison was reached"
     * means under the CLI SAPI.
     */
    #[Test]
    public function theBanMessageIsGuardedByTheAuthenticationBucket(): void
    {
        $body = $this->normalizedMethodBody('/base/fs_login.php', 'log_in');

        $banCheck = strpos($body, 'is_banned($ip)');
        $banMessage = strpos($body, self::BAN_MESSAGE_MARKER);
        $comparison = strpos($body, 'log_in_user(');

        $this->assertNotFalse($banCheck);
        $this->assertNotFalse($banMessage);
        $this->assertNotFalse($comparison);
        $this->assertLessThan($banMessage, $banCheck, 'the ban message is emitted on the authentication bucket only');
        $this->assertLessThan($comparison, $banMessage, 'the password comparison runs only when the bucket is clean');
    }

    // =====================================================================
    // Harness
    // =====================================================================

    /**
     * Builds the anonymous reset request: `page=password_reset` with no
     * `token`/`nick` in the query, so `public_core()` selects the request
     * branch.
     */
    private function resetRequest(array $fields): Request
    {
        return Request::create('/index.php?page=password_reset', 'POST', $fields);
    }

    private function resetPost(array $fields): Request
    {
        return Request::create('/index.php?page=password_reset', 'POST', $fields);
    }

    /**
     * A signed reset-completion URL, i.e. one that would pass
     * `validate_signed_reset_url()`.
     */
    private function signedResetUrl(): string
    {
        return SignedUrlService::sign(
            'https://panel.test/index.php?page=password_reset&nick=nobody&token=reset-token',
            time() + 3600
        );
    }

    /**
     * A reset-completion body that satisfies every password check, so the only
     * thing that can stop the request is the guard under test.
     */
    private function completionFields(): array
    {
        return [
            'new_password' => 'brand-new-password',
            'new_password2' => 'brand-new-password',
        ];
    }

    /**
     * Drives `public_core()` exactly as the dispatcher does for an anonymous
     * POST, and returns the controller so its public outcome flags are
     * readable.
     */
    private function driveResetRequest(Request $request, ?string $uri = null, array $extraFields = []): object
    {
        if ($uri !== null) {
            $request = Request::create($uri, 'POST', array_merge($request->request->all(), $extraFields));
        }

        $controller = new ResetControllerHarness($request, $this->coreLog);
        $controller->runPublicCore();

        return $controller;
    }

    /**
     * The ban check authentication performs, observed through the real
     * `fs_login::log_in()`.
     */
    private function authenticationBanErrors(): array
    {
        $login = new \fs_login();
        $this->setPrivateProperty($login, 'core_log', $this->loginCoreLog);

        $candidate = new \stdClass();
        $login->log_in($candidate);

        return array_values(array_filter(
            $this->loginCoreLog->errors,
            static fn (string $error): bool => str_contains($error, self::BAN_MESSAGE_MARKER)
        ));
    }

    private function fillBucket(string $namespace): void
    {
        $filter = new \fs_ip_filter($namespace);
        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt(self::CLIENT_IP);
        }
    }

    private function authenticationBucket(): string
    {
        return FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log';
    }

    private function resetBucket(): string
    {
        return FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'password_reset_ip.log';
    }

    private function attemptsIn(string $bucket): int
    {
        if (!file_exists($bucket)) {
            return 0;
        }

        $attempts = 0;
        foreach (file($bucket, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode(';', $line);
            if (($fields[0] ?? '') === self::CLIENT_IP) {
                $attempts += (int) ($fields[1] ?? 0);
            }
        }

        return $attempts;
    }

    private function removeBuckets(): void
    {
        foreach ([$this->authenticationBucket(), $this->resetBucket()] as $bucket) {
            if (file_exists($bucket)) {
                unlink($bucket);
            }
        }
    }

    private function normalizedMethodBody(string $relativePath, string $method): string
    {
        $source = file_get_contents(FS_FOLDER . $relativePath);
        $this->assertNotFalse($source, $relativePath . ' must be readable');

        return (string) preg_replace('/\s+/', ' ', $this->methodBody((string) $source, $method));
    }

    private function setPrivateProperty(object $instance, string $propertyName, object $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function resetCsrfManager(): void
    {
        $reflection = new \ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        $tokenVerified = $reflection->getProperty('tokenVerified');
        $tokenVerified->setAccessible(true);
        $tokenVerified->setValue(null, false);
    }
}

/**
 * `password_reset` with the framework constructor bypassed, the same seam
 * tests/Controller/LoginPublicCoreCsrfTest.php uses for the login page.
 */
final class ResetControllerHarness extends \password_reset
{
    public function __construct(Request $request, ResetFlowCoreLog $coreLog)
    {
        $this->request = $request;
        $this->core_log = $coreLog;
        $this->class_name = 'password_reset';
        $this->template = '';
    }

    public function runPublicCore(): void
    {
        $this->public_core();
    }
}

/**
 * The error and message lists the reset template reads, isolated from
 * fs_core_log's shared static state: every accessor the controller uses is
 * overridden.
 */
final class ResetFlowCoreLog extends \fs_core_log
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $advices = [];

    public function __construct(private string $name = 'password_reset')
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
        $this->messages[] = (string) $msg;
    }

    public function new_advice($msg, $context = [])
    {
        $this->advices[] = (string) $msg;
    }

    public function save($msg, $type = 'error', $alert = FALSE, $context = [])
    {
    }

    public function get_errors()
    {
        return $this->errors;
    }

    public function get_messages()
    {
        return $this->messages;
    }

    public function set_user_nick($nick)
    {
    }

    public function user_nick()
    {
        return '';
    }
}
