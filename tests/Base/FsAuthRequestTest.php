<?php
/**
 * Tests for fs_auth_request — the single definition of the request facts the
 * authentication boundary depends on (AAG-02, AAG-03, AAG-04, AAG-06).
 *
 * Pure array-in/boolean-out: no superglobals, no kernel, no database.
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FsAuthRequestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // base/fs_auth_request.php is a dependency-free legacy file and is not
        // PSR-4 autoloaded, so it is loaded explicitly here. The load is guarded
        // so that a missing file surfaces as a test error for this class instead
        // of a process-level fatal that would abort the whole suite.
        $path = FS_FOLDER . '/base/fs_auth_request.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('provideRequestedPageCases')]
    public function testRequestedPageMirrorsTheDispatcherRule(array $query, string $expected): void
    {
        $this->assertSame($expected, \fs_auth_request::requestedPage($query));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideRequestedPageCases(): array
    {
        return [
            'canonical login' => [['page' => 'login'], 'login'],
            'login with trailing space' => [['page' => 'login '], 'login'],
            'another canonical page' => [['page' => 'admin_info'], 'admin_info'],
            'uppercase is invalid' => [['page' => 'LOGIN'], ''],
            'empty string is invalid' => [['page' => ''], ''],
            'leading digit is invalid' => [['page' => '9lives'], ''],
            'absent page' => [[], ''],
            'array input is not a string' => [['page' => ['login']], ''],
            'null input is not a string' => [['page' => null], ''],
            'integer input is not a string' => [['page' => 123], ''],
            'boolean input is not a string' => [['page' => true], ''],
        ];
    }

    public function testRequestedPageNeverRaisesOnNonStringInput(): void
    {
        // AAG-03: in PHP 8 a trim()/preg_match() on an array is a TypeError, which
        // would turn an access-control decision into a 500. The guard must come first.
        $this->assertSame('', \fs_auth_request::requestedPage(['page' => ['login']]));
        $this->assertSame('', \fs_auth_request::requestedPage(['page' => null]));
    }

    /**
     * AAG-06: the anonymous access boundary is a set, and it is exactly these two
     * pages. Asserting the set by value (not only membership of its members) is
     * what makes widening it a deliberate, reviewable edit instead of a passing
     * test.
     */
    public function testPublicPagesAreExactlyTheLoginAndResetPages(): void
    {
        $this->assertSame(['login', 'password_reset'], \fs_auth_request::PUBLIC_PAGES);
    }

    #[DataProvider('providePublicPageCases')]
    public function testIsPublicPage(string $page, bool $expected): void
    {
        $this->assertSame($expected, \fs_auth_request::isPublicPage($page));
    }

    /**
     * A page that is not a canonical page name is never public, so the predicate
     * stays fail-closed on the empty string the canonicaliser returns.
     *
     * @return array<string, array{string, bool}>
     */
    public static function providePublicPageCases(): array
    {
        return [
            'login' => ['login', true],
            'the password-recovery page' => ['password_reset', true],
            'an administrative page' => ['admin_info', false],
            'no page named' => ['', false],
            'uppercase is not canonical' => ['LOGIN', false],
            'an unrelated page' => ['portal', false],
            'a page whose name merely starts with the reset name' => ['password_reset_admin', false],
        ];
    }

    /**
     * AAG-02 + AAG-06: the set is consulted with the canonical page, never with a
     * raw query value, and a non-string page can never land in it.
     *
     * @param array<string, mixed> $query
     */
    #[DataProvider('providePublicPageQueryCases')]
    public function testIsPublicPageConsumesTheCanonicalPage(array $query, bool $expected): void
    {
        $this->assertSame(
            $expected,
            \fs_auth_request::isPublicPage(\fs_auth_request::requestedPage($query))
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function providePublicPageQueryCases(): array
    {
        return [
            'canonical reset page' => [['page' => 'password_reset'], true],
            'reset page with a trailing space' => [['page' => 'password_reset '], true],
            'array input names no page' => [['page' => ['password_reset']], false],
            'absent page' => [[], false],
            'uppercase reset page is not canonical' => [['page' => 'PASSWORD_RESET'], false],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    #[DataProvider('provideStealthSubmissionCases')]
    public function testIsStealthSubmission(string $method, array $query, array $post, bool $expected): void
    {
        $this->assertSame($expected, \fs_auth_request::isStealthSubmission($method, $query, $post));
    }

    /**
     * Semantics moved verbatim from base/fs_maintenance_mode.php:555-566, so the
     * parity cases below pin the same shape the maintenance guard relies on.
     *
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function provideStealthSubmissionCases(): array
    {
        return [
            'post with nlogin in the query' => ['POST', ['nlogin' => '1'], [], true],
            'post with user and password' => ['POST', [], ['user' => 'a', 'password' => 'b'], true],
            'post with user and email' => ['POST', [], ['user' => 'a', 'email' => 'b'], true],
            'lowercase post method' => ['post', ['nlogin' => '1'], [], true],
            'get with nlogin is not a submission' => ['GET', ['nlogin' => '1'], [], false],
            'get with credentials is not a submission' => ['GET', [], ['user' => 'a', 'password' => 'b'], false],
            'post without any login field' => ['POST', [], [], false],
            'post with user but no password or email' => ['POST', [], ['user' => 'a'], false],
            'post with password but no user' => ['POST', [], ['password' => 'b'], false],
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    #[DataProvider('provideCredentialSubmissionCases')]
    public function testHasCredentialSubmission(string $method, array $post, bool $expected): void
    {
        $this->assertSame($expected, \fs_auth_request::hasCredentialSubmission($method, $post));
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function provideCredentialSubmissionCases(): array
    {
        return [
            'post with password and user' => ['POST', ['password' => 'b', 'user' => 'a'], true],
            'post with password and nick' => ['POST', ['password' => 'b', 'nick' => 'a'], true],
            'post with password alone' => ['POST', ['password' => 'b'], false],
            'post with user alone' => ['POST', ['user' => 'a'], false],
            'get with credentials' => ['GET', ['password' => 'b', 'user' => 'a'], false],
            'post without credentials' => ['POST', [], false],
        ];
    }
}
