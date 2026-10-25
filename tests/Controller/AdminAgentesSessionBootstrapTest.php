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

namespace Tests\Controller;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Controller\Concerns\ExtractsMethodBody;

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/admin_agentes.php';

/**
 * STH-05 / SC-01 — the duplicated-petition guard is no longer a bare
 * session_start().
 *
 * `admin_agentes::isDuplicatedPetition()` opened the session with
 * `session_start()` and no name when none was active: PHP then used the default
 * name, emitted a PHPSESSID cookie and re-opened the dual-cookie competition
 * that consolidate-session-csrf closed (SC-01…SC-05). It is now delegated to the
 * shared session accessor, which fixes the name, the cookie parameters and
 * strict mode before the start.
 *
 * What this test can observe: session_name()/session_status() after the guard
 * ran. A name other than PHPSESSID can only come from a name set BEFORE the
 * start, because PHP refuses to rename an active session. What it cannot
 * observe is the Set-Cookie header (CLI keeps no header list): the "no PHPSESSID
 * cookie" half of SC-01 is the operator-owned live probe.
 */
final class AdminAgentesSessionBootstrapTest extends TestCase
{
    use ExtractsMethodBody;

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_SESSION = [];
        $_COOKIE = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        SessionManager::reset();
        $_SESSION = [];
        $_COOKIE = [];

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testTheDuplicatePetitionGuardUsesTheSharedSessionName(): void
    {
        $controller = (new ReflectionClass(\admin_agentes::class))->newInstanceWithoutConstructor();
        $guard = new ReflectionMethod(\admin_agentes::class, 'isDuplicatedPetition');
        $guard->setAccessible(true);

        $firstCall = $guard->invoke($controller, 'petition-a');

        $this->assertFalse($firstCall, 'The first petition must not be reported as duplicated.');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'The guard must leave the session open.');
        $this->assertSame(SessionManager::resolveSessionName(), session_name());
        $this->assertNotSame('PHPSESSID', session_name(), 'A default-named session re-opens the dual-cookie regression.');

        $this->assertTrue($guard->invoke($controller, 'petition-a'), 'The guard must still deduplicate.');
        $this->assertFalse($guard->invoke($controller, 'petition-b'));
    }

    public function testTheGuardDoesNotStartTheSessionByNameItself(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller/admin_agentes.php');
        $body = $this->methodBody($source, 'isDuplicatedPetition');

        $this->assertStringContainsString('SessionManager::getInstance()', $body);
        $this->assertStringNotContainsString('session_start(', $body, 'The guard must delegate the start to the shared accessor.');
    }
}
