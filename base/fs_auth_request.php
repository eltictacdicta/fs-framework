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
 * depends on: the canonical requested page, whether the request is a login
 * submission, and whether it carries credentials.
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
