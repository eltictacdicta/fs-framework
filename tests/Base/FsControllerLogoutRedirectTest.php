<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Source contract for the legacy logout branch in base/fs_controller.php.
 *
 * Behavioural coverage of the branch is not possible in-process: it calls
 * exit(), which terminates the PHPUnit runner (and #[RunInSeparateProcess]
 * cannot survive an exit() either), so the branch is pinned by scanning its
 * exact source. Only that branch is inspected: base/fs_controller.php carries
 * other "Location:" headers, so a file-wide assertion would pass for the wrong
 * reason.
 *
 * Guarantee under contract: after a logout the anonymous visitor lands on the
 * site home (index.php) and the framework login form is never rendered from
 * this branch. The final destination is then decided by PublicAccessGate on
 * the following request, which is the component that knows stealth mode and
 * the public login redirects registered by plugins.
 */
class FsControllerLogoutRedirectTest extends TestCase
{
    private const CONTROLLER_PATH = '/base/fs_controller.php';

    private const LOGOUT_MARKER = "query->has('logout')";

    private const LOGIN_TEMPLATE_ASSIGNMENT = "\$this->template = 'login/default';";

    private function controllerSource(): string
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        $this->assertNotFalse($source, 'base/fs_controller.php must be readable');

        return (string) $source;
    }

    /**
     * Extracts the body of the `if ($this->request->query->has('logout')) { ... }`
     * branch: locate the unique marker, take the first opening brace after it and
     * walk braces (skipping strings and comments) until the matching close.
     */
    private function extractLogoutBranch(): string
    {
        $source = $this->controllerSource();

        $occurrences = substr_count($source, self::LOGOUT_MARKER);
        $this->assertSame(
            1,
            $occurrences,
            'The logout guard must appear exactly once in base/fs_controller.php so the extraction is unambiguous; found '
            . $occurrences
        );

        $markerPosition = strpos($source, self::LOGOUT_MARKER);
        $this->assertNotFalse(
            $markerPosition,
            'Could not locate the logout guard (' . self::LOGOUT_MARKER . ') in base/fs_controller.php'
        );

        $openBrace = strpos($source, '{', (int) $markerPosition);
        $this->assertNotFalse($openBrace, 'The logout guard has no opening brace');

        $closeBrace = $this->findBlockEnd($source, (int) $openBrace);

        return substr($source, (int) $openBrace, $closeBrace - (int) $openBrace + 1);
    }

    private function findBlockEnd(string $source, int $openBrace): int
    {
        $depth = 0;
        $length = strlen($source);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = $openBrace; $i < $length; $i++) {
            $char = $source[$i];
            $next = ($i + 1 < $length) ? $source[$i + 1] : '';

            if ($inLineComment) {
                $inLineComment = ($char !== "\n");
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($inSingleQuote) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === "'") {
                    $inSingleQuote = false;
                }
                continue;
            }

            if ($inDoubleQuote) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inDoubleQuote = false;
                }
                continue;
            }

            if ($char === '/' && $next === '/') {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($char === "'") {
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        $this->fail('Unbalanced braces while extracting the logout branch of base/fs_controller.php');
    }

    private function normalizedBranch(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->extractLogoutBranch());
    }

    #[Test]
    public function logoutBranchIsLocatedOnceWithItsLogoutCall(): void
    {
        $branch = $this->normalizedBranch();

        $this->assertStringContainsString(
            '$this->login_tools->log_out();',
            $branch,
            'The extracted branch must be the logout branch'
        );
    }

    #[Test]
    public function logoutBranchRedirectsToTheSiteHome(): void
    {
        $branch = $this->normalizedBranch();

        $this->assertStringContainsString(
            "header('Location: index.php');",
            $branch,
            'After a logout the visitor must land on the site home'
        );

        $this->assertStringNotContainsString(
            'Location: index.php?page=login',
            $branch,
            'The logout branch must not bounce the visitor back to the login page'
        );
    }

    #[Test]
    public function logoutBranchStopsExecution(): void
    {
        $branch = $this->normalizedBranch();

        $this->assertMatchesRegularExpression(
            '/\bexit\s*(\(\))?\s*;/',
            $branch,
            'The logout branch must stop execution before the framework can render anything'
        );
    }

    #[Test]
    public function logoutBranchNeverAssignsTheLoginTemplate(): void
    {
        $branch = $this->normalizedBranch();

        $this->assertStringNotContainsString(
            'login/default',
            $branch,
            'The logout branch must not reference the framework login template'
        );

        $this->assertStringNotContainsString(
            '$this->template',
            $branch,
            'The logout branch must not assign any template'
        );
    }

    /**
     * Proves the scan is scoped to the logout branch: the file still holds the
     * neighbouring redirects and the hidden/legacy login template assignment,
     * but none of them leak into the extracted branch.
     */
    #[Test]
    public function extractionIsScopedToTheLogoutBranch(): void
    {
        $source = (string) preg_replace('/\s+/', ' ', $this->controllerSource());
        $branch = $this->normalizedBranch();

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "header('Location:"),
            'The file has several redirects, so a file-wide assertion would be a false positive'
        );

        $this->assertStringContainsString(
            "header('Location: index.php?page=force_password_change');",
            $source,
            'The neighbouring redirect must still exist in the file'
        );

        $this->assertStringNotContainsString(
            'force_password_change',
            $branch,
            'The extracted branch must not swallow the neighbouring redirect'
        );

        $this->assertStringContainsString(
            self::LOGIN_TEMPLATE_ASSIGNMENT,
            $source,
            'The hidden/legacy login entry must keep its template assignment'
        );

        $this->assertStringNotContainsString(
            self::LOGIN_TEMPLATE_ASSIGNMENT,
            $branch,
            'That template assignment must live outside the logout branch'
        );
    }
}
