<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Spec: docs/reviews/audit-2026-06-12-spec.md section 3.5 (L1).
 *
 * L1 enforces the architectural rule that admin_user.php must read all
 * HTTP inputs through the Symfony Request object ($this->request->query
 * and $this->request->request) instead of either:
 *   - raw $_GET / $_POST superglobals, OR
 *   - the legacy filter_input(INPUT_GET, ...) / filter_input(INPUT_POST, ...)
 *     helper (used in fs_login.php pre-migration; the rest of the core
 *     controllers no longer use it).
 *
 * This brings admin_user into line with the rest of the core controllers
 * (login.php, force_password_change.php, password_reset.php,
 * admin_email.php, admin_system_branding.php, admin_stealth.php,
 * admin_home.php) which already migrated.
 *
 * Why static source analysis (instead of behaviour tests):
 *   - The fs_controller base constructor is heavy: it boots a database
 *     connection, loads user, menu, extensions, and dozens of plugins.
 *     Instantiating admin_user in tests requires mocking all of those.
 *   - The architectural rule is purely about which API the code uses
 *     to read HTTP input. Static analysis is the most direct, cheapest
 *     way to enforce it.
 *   - Pattern is the same one used by tests/Base/RandomStringHardeningTest.php
 *     for the str_shuffle → random_bytes migration.
 *
 * Scenarios covered (per spec 3.5.3):
 *   L1.1 — snick comes from $this->request->query, not $_GET/filter_input
 *   L1.2 — POST mutations read from $this->request->request->has()
 *   L1.2+ — POST values read from $this->request->request->get()
 */
final class AdminUserInputAccessTest extends TestCase
{
    private const TARGET_FILE = __DIR__ . '/../../controller/admin_user.php';

    /**
     * L1.1 — no direct $_GET[...] reads AND no filter_input(INPUT_GET, ...).
     *
     * Covers the snick lookup and any future GET parameter access.
     * Allowed: $_SERVER (legit for IP). Not allowed: $_GET[...] in any
     * form, nor the legacy filter_input(INPUT_GET, ...) helper.
     */
    public function testNoDirectGetSuperglobalReadsInAdminUser(): void
    {
        $this->assertFileExists(self::TARGET_FILE,
            'Target controller must exist: ' . self::TARGET_FILE);

        $content = file_get_contents(self::TARGET_FILE);

        $superGlobal = preg_match_all('/\$_GET\[/', $content);
        $filterInput = preg_match_all('/filter_input\s*\(\s*INPUT_GET\s*,/', $content);

        $total = $superGlobal + $filterInput;
        $this->assertSame(0, $total,
            'admin_user.php no debe leer GET params via $_GET[...] '
            . '(' . $superGlobal . ' ocurrencias) ni via '
            . 'filter_input(INPUT_GET, ...) (' . $filterInput . ' ocurrencias). '
            . 'Usar $this->request->query->get() / ->has(). '
            . 'Total: ' . $total . ' ocurrencias.'
        );
    }

    /**
     * L1.2 — no isset($_POST[ existence checks AND no filter_input(INPUT_POST, ...).
     *
     * All POST parameter presence checks must use
     * $this->request->request->has(), not the legacy isset() or
     * filter_input(INPUT_POST, ...) helpers.
     */
    public function testPostExistenceChecksUseRequest(): void
    {
        $this->assertFileExists(self::TARGET_FILE,
            'Target controller must exist: ' . self::TARGET_FILE);

        $content = file_get_contents(self::TARGET_FILE);

        $issetPost = preg_match_all('/isset\s*\(\s*\$_POST\[/', $content);
        $filterInput = preg_match_all('/filter_input\s*\(\s*INPUT_POST\s*,/', $content);

        $total = $issetPost + $filterInput;
        $this->assertSame(0, $total,
            'admin_user.php debe usar $this->request->request->has() '
            . 'en lugar de isset($_POST[...]) (' . $issetPost . ' ocurrencias) '
            . 'o filter_input(INPUT_POST, ...) (' . $filterInput . ' ocurrencias). '
            . 'Total: ' . $total . ' ocurrencias.'
        );
    }

    /**
     * L1.2+ — no direct $_POST[ value reads.
     *
     * Direct $_POST[...] reads in conditionals or assignments. (The
     * filter_input case is covered by testPostExistenceChecksUseRequest
     * since filter_input always reads both presence and value at once.)
     *
     * Allowed: the result of a $this->request->request->get() call.
     * Not allowed: `if ($_POST[...] ...)`, `= $_POST[...]`, etc.
     */
    public function testNoDirectPostValueReadsInAdminUser(): void
    {
        $this->assertFileExists(self::TARGET_FILE,
            'Target controller must exist: ' . self::TARGET_FILE);

        $content = file_get_contents(self::TARGET_FILE);

        // Direct $_POST[...] reads in conditionals or assignments.
        // Patterns to catch: `if ($_POST[...] ...)`, `= $_POST[...`
        $conditional = preg_match_all('/if\s*\(\s*\$_POST\[/', $content);
        $assignment = preg_match_all('/=\s*\$_POST\[/', $content);

        $total = $conditional + $assignment;
        $this->assertSame(0, $total,
            'admin_user.php no debe leer $_POST[...] directamente en '
            . 'if / asignaciones. Usar $this->request->request->get(). '
            . 'Encontradas: ' . $conditional . ' condicionales + '
            . $assignment . ' asignaciones = ' . $total . ' ocurrencias.');
    }
}
