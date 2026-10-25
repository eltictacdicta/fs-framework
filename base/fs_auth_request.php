<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * The single definition of the request facts the authentication boundary
 * depends on: the canonical requested page, the set of pages served
 * anonymously, whether the request is a login submission, and whether it
 * carries credentials.
 *
 * It is intentionally dependency-free (no namespace, no autoloader, no
 * superglobals) because it is consumed from two sides that cannot be loaded
 * together reliably: base/fs_maintenance_mode.php (loaded directly by the
 * system_updater plugin, before Composer) and src/Core/StealthMode.php
 * (namespaced, loaded by the gate). Both sides used to keep their own copy of
 * the login-submission predicate, and the two copies could drift apart.
 *
 * Everything is array-in/boolean-out so the rules are unit-testable without a
 * booted kernel and without touching $_GET/$_POST.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
final class fs_auth_request
{
    /**
     * The pages the application serves to an anonymous visitor: the login form
     * and the password-recovery flow.
     *
     * This is the anonymous access boundary. Widening it is a security decision,
     * not a convenience: a page listed here is reachable by anyone who can send a
     * request, so it may not perform authenticated work. `password_reset` earns
     * its place because it is the only entrance to the recovery flow — the "I
     * forgot my password" link on the login form points at it — and because its
     * controller owns a dedicated throttle bucket, validates before counting and
     * requires a CSRF token (login-throttle-isolation LTI-01…LTI-05).
     *
     * The list is consulted with the *canonical* page name (requestedPage()), so a
     * raw query value can never widen it and a non-string page names no page at
     * all.
     *
     * @var list<string>
     */
    public const PUBLIC_PAGES = ['login', 'password_reset'];

    /**
     * Whether an anonymous request may reach this canonical page.
     *
     * Fail-closed by construction: the empty string the canonicaliser returns for
     * an absent, malformed or non-string page is not in the set.
     */
    public static function isPublicPage(string $page): bool
    {
        return in_array($page, self::PUBLIC_PAGES, true);
    }

    /**
     * Canonical requested-page name, or an empty string when the request names
     * no valid page.
     *
     * Mirrors index.php::fs_get_requested_page_name() exactly: reject a
     * non-string, trim, then accept only /^[a-z][a-z0-9_]*$/. The gate MUST
     * consume this rule rather than compare the raw query value, otherwise the
     * gate and the dispatcher would disagree again (e.g. page=login%20 resolves
     * to `login` for the dispatcher but is not a raw `login`).
     *
     * A non-string value (array input such as ?page[]=login, or null) resolves
     * to the empty string and never reaches trim()/preg_match(): in PHP 8 that
     * would be a TypeError and would turn an access-control decision into a 500.
     *
     * @param array<string, mixed> $query
     */
    public static function requestedPage(array $query): string
    {
        $page = $query['page'] ?? null;
        if (!is_string($page)) {
            return '';
        }

        $page = trim($page);
        if ($page === '') {
            return '';
        }

        return preg_match('/^[a-z][a-z0-9_]*$/', $page) === 1 ? $page : '';
    }

    /**
     * Whether the request is a login submission: a POST that either carries
     * `nlogin` in the query or a credential body (`user` with `password` or
     * `email`).
     *
     * The semantics are the ones base/fs_maintenance_mode.php used locally; the
     * maintenance guard and StealthMode now share this definition.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public static function isStealthSubmission(string $method, array $query, array $post): bool
    {
        if (strtoupper($method) !== 'POST') {
            return false;
        }

        if (isset($query['nlogin'])) {
            return true;
        }

        return isset($post['user']) && (isset($post['password']) || isset($post['email']));
    }

    /**
     * Whether the request carries a credential submission: a POST with a
     * `password` plus a `user` or `nick` field.
     *
     * This is the shape that can authenticate, so it is the predicate every
     * credential-consuming guard must share.
     *
     * @param array<string, mixed> $post
     */
    public static function hasCredentialSubmission(string $method, array $post): bool
    {
        if (strtoupper($method) !== 'POST') {
            return false;
        }

        if (!isset($post['password'])) {
            return false;
        }

        return isset($post['user']) || isset($post['nick']);
    }
}
