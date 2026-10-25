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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * DSH-01 / DSH-04 (VULN-0005) — credential material is described, never reproduced.
 *
 * The served tree is the project root: the edge layer mitigates that (DSH-02), but a
 * document that pastes a real credential is still a leak in git, in a backup and in any
 * deployment that reuses this layout. This scan is the invariant that makes a future
 * verification report fail the suite instead of reaching production.
 *
 * ## The rule is ADJACENCY, and that is load-bearing
 *
 * A finding is a 40- or 64-character hexadecimal blob written next to one of the tokens
 * `logkey`, `auth_sig`, `password` or `token` — i.e. `logkey=<hex>`, `logkey: <hex>`,
 * `"logkey": "<hex>"`.
 *
 * A bare "any 40/64-character hex blob" rule is FORBIDDEN and MUST NOT replace this one:
 * `tests/bootstrap.php` defines a deterministic 64-hex `FS_SECRET_KEY` that is a test
 * fixture, not a credential, and it is not adjacent to any of the four tokens, so the
 * adjacency rule does not match it. That near-miss is pinned by
 * {@see self::testTheDeterministicTestSecretIsNotAFalsePositive()} below. A
 * non-adjacency rule would flag it, and the false positive would force someone to
 * weaken or ignore the control — which is exactly how this class of leak survives.
 *
 * A legitimate 40/64-hex value that IS adjacent to a token opts out with an inline
 * `# nosecret` (or `// nosecret`) marker, the same marker the pre-commit hook honours.
 *
 * ## What this test cannot assert
 *
 * It is a filesystem scan, not an HTTP probe. That the live server returns `403` for
 * `openspec/` is the operator handoff (DSH-05, `nginx -t` plus a live request), and
 * rotating the burned `admin` `log_key` is likewise operator-owned: the published value
 * already lives in git history and in the public upstream repository, so redaction is
 * hygiene and rotation is the closure.
 */
final class ServedTreeSecretScanTest extends TestCase
{
    /** The document that published the live credential before this change. */
    private const REPORT_WITH_PUBLISHED_CREDENTIAL =
        'openspec/changes/ventas-clientes-controller-dedup/verify-report.md';

    /** The document that redacted the key but still printed a literal session id. */
    private const REPORT_WITH_PUBLISHED_SESSION =
        'openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md';

    /** Committed spec prose that must keep its `FSSESS_xxx` placeholder untouched. */
    private const SPEC_WITH_PROSE_PLACEHOLDER = 'openspec/specs/session-consolidation/spec.md';

    /**
     * Directories that are not served documents: third-party code, runtime state and
     * separately versioned plugin repositories. Everything else under the project root
     * is scanned, including dot-free runtime directories the edge layer denies.
     */
    private const EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', 'tmp', 'plugins'];

    /** Binary payloads cannot hold a credential-shaped text line. */
    private const SKIPPED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp', 'svgz',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'phar', 'apk',
        'pdf', 'db', 'sqlite', 'sqlite3', 'so', 'o', 'a', 'pyc', 'class', 'jar',
    ];

    private const ADJACENCY_TOKENS = ['logkey', 'auth_sig', 'password', 'token'];

    /** Files above this size are assets, not documents. */
    private const MAX_SCANNED_BYTES = 2000000;

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null && is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        $this->fixturePath = null;

        parent::tearDown();
    }

    /**
     * DSH-04 scenario "the scan is green on the shipped tree".
     */
    #[Test]
    public function testTheShippedTreeCarriesNoCredentialAdjacentToASecretToken(): void
    {
        $findings = $this->findingsInServedTree();

        $this->assertSame(
            [],
            $findings,
            "Credential-shaped value found under the served tree.\n"
            . "Replace the literal value with <redacted>, or opt a legitimate fixture out "
            . "with an inline '# nosecret' marker.\n"
            . implode("\n", $this->describeFindings($findings))
        );
    }

    /**
     * DSH-04 scenario "the scan goes red on a pasted credential".
     *
     * The fixture is written into the served tree and found by the same enumeration
     * the green case uses, so the assertion covers the detection path end to end and
     * not just a helper called directly.
     */
    #[Test]
    public function testTheScanGoesRedOnAPastedCredentialFixtureUnderTheServedTree(): void
    {
        $relative = 'secret-scan-fixture-' . bin2hex(random_bytes(4)) . '.md';
        $this->fixturePath = FS_FOLDER . '/' . $relative;

        // Built programmatically so this test's own source carries no literal blob.
        $pasted = 'auth_sig=' . str_repeat('ab', 32);
        $written = file_put_contents(
            $this->fixturePath,
            "# Temporary scan fixture\n\nGET /index.php with " . $pasted . "\n"
        );
        $this->assertNotFalse($written, 'The fixture must be written under the served tree.');

        $withFixture = $this->findingsInServedTree();
        $this->assertContains(
            $relative,
            array_column($withFixture, 'file'),
            'The scan must name the fixture that pastes a credential.'
        );

        unlink($this->fixturePath);
        $this->fixturePath = null;

        $withoutFixture = $this->findingsInServedTree();
        $this->assertNotContains(
            $relative,
            array_column($withoutFixture, 'file'),
            'Removing the fixture must remove the finding it caused.'
        );
    }

    /**
     * DSH-01 scenario "the deterministic test secret is not a false positive".
     *
     * This is the near-miss that forbids the naive rule. The line below carries a
     * 64-character hex blob, so a bare 64-hex pattern would match it; the adjacency
     * rule does not, because the blob is a `define()` value and not written next to
     * `logkey`/`auth_sig`/`password`/`token`. If someone ever "simplifies" the rule,
     * this test fails and points at the reason.
     */
    #[Test]
    public function testTheDeterministicTestSecretIsNotAFalsePositive(): void
    {
        $bootstrap = FS_FOLDER . '/tests/bootstrap.php';
        $this->assertFileExists($bootstrap);

        $definition = null;
        foreach ($this->readLines($bootstrap) as $line) {
            if (str_contains($line, "define('FS_SECRET_KEY'")) {
                $definition = $line;
                break;
            }
        }

        if ($definition === null) {
            $this->fail('tests/bootstrap.php must define FS_SECRET_KEY.');
        }

        $this->assertMatchesRegularExpression(
            '/[0-9a-f]{64}/',
            $definition,
            'The near-miss only exists while the test secret is a 64-hex value.'
        );
        $this->assertNull(
            self::credentialTokenIn($definition),
            'The adjacency rule must not match the deterministic FS_SECRET_KEY fixture.'
        );
        $this->assertSame([], self::scanFile($bootstrap));
    }

    /**
     * DSH-01 scenario "the published credential no longer exists under the served tree"
     * and scenario "the narrative keeps its evidentiary value".
     */
    #[Test]
    public function testPublishedPlanningReportsCarryNoLiteralCredentialValue(): void
    {
        foreach ([self::REPORT_WITH_PUBLISHED_CREDENTIAL, self::REPORT_WITH_PUBLISHED_SESSION] as $relative) {
            $path = FS_FOLDER . '/' . $relative;
            $this->assertFileExists($path);

            $this->assertSame([], self::scanFile($path), $relative . ' still has a credential-shaped value.');

            $contents = $this->readFile($path);

            $this->assertDoesNotMatchRegularExpression(
                '/FSSESS_[0-9a-f]{6,}/i',
                $contents,
                $relative . ' still prints a literal session identifier; the prose placeholder is FSSESS_xxx.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/_token[^\n]{0,40}?[0-9a-f]{16,}/i',
                $contents,
                $relative . ' still prints a literal CSRF token value.'
            );
        }
    }

    /**
     * DSH-01 scenario "the narrative keeps its evidentiary value" — the redaction
     * replaces values, it does not delete the evidence.
     */
    #[Test]
    public function testTheRedactedReportKeepsItsRequestShapeCodePathAndConclusion(): void
    {
        $contents = $this->readFile(FS_FOLDER . '/' . self::REPORT_WITH_PUBLISHED_CREDENTIAL);

        foreach (['<redacted>', 'curl -b', 'user=admin', 'logkey=', 'auth_sig=', 'FSSESS_', 'HTTP=200', '→ PASS'] as $kept) {
            $this->assertStringContainsString(
                $kept,
                $contents,
                'The redacted report must keep its request shape, code path and conclusion.'
            );
        }
    }

    /**
     * DSH-01 scenario "prose placeholders are not redacted away".
     */
    #[Test]
    public function testProsePlaceholdersAreNotRedactedAway(): void
    {
        $spec = FS_FOLDER . '/' . self::SPEC_WITH_PROSE_PLACEHOLDER;
        $this->assertFileExists($spec);
        $this->assertStringContainsString('FSSESS_xxx', $this->readFile($spec));
        $this->assertSame([], self::scanFile($spec), 'A prose placeholder is not a literal value.');
    }

    /**
     * The token whose value sits next to the blob on this line, or null when the line
     * is clean or opted out with the inline marker.
     */
    private static function credentialTokenIn(string $line): ?string
    {
        if (preg_match('~(?:#|//)[ \t]*nosecret\b~i', $line) === 1) {
            return null;
        }

        $tokens = implode('|', array_map(static fn(string $token): string => preg_quote($token, '/'), self::ADJACENCY_TOKENS));
        $pattern = '/(?:' . $tokens . ')["\']?[ \t]*[=:][ \t]*["\']?(?:[0-9a-fA-F]{64}|[0-9a-fA-F]{40})\b/';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        if (preg_match('/' . $tokens . '/i', $matches[0], $tokenMatch) !== 1) {
            return null;
        }

        return strtolower($tokenMatch[0]);
    }

    /**
     * @return array<int, array{line: int, token: string}>
     */
    private static function scanFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false || self::looksBinary($contents)) {
            return [];
        }

        $findings = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            $token = self::credentialTokenIn($line);
            if ($token !== null) {
                $findings[] = ['line' => $index + 1, 'token' => $token];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array{file: string, line: int, token: string}>
     */
    private function findingsInServedTree(): array
    {
        $findings = [];
        foreach ($this->servedTreeFiles() as $path) {
            foreach (self::scanFile($path) as $finding) {
                $findings[] = [
                    'file' => ltrim(str_replace(FS_FOLDER, '', $path), '/'),
                    'line' => $finding['line'],
                    'token' => $finding['token'],
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function servedTreeFiles(): array
    {
        $directories = new RecursiveDirectoryIterator(FS_FOLDER, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator(
            $directories,
            static function (SplFileInfo $file): bool {
                $name = $file->getFilename();

                if ($file->isDir()) {
                    // Dot-paths (including .git and every cache) are denied at the edge
                    // and are never documents.
                    return !str_starts_with($name, '.')
                        && !in_array($name, self::EXCLUDED_DIRECTORIES, true);
                }

                return true;
            }
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($filtered) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !$file->isReadable()) {
                continue;
            }

            if ($file->getSize() > self::MAX_SCANNED_BYTES) {
                continue;
            }

            if (in_array(strtolower($file->getExtension()), self::SKIPPED_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<int, array{file: string, line: int, token: string}> $findings
     * @return array<int, string>
     */
    private function describeFindings(array $findings): array
    {
        return array_map(
            static fn(array $finding): string => sprintf(
                ' - %s:%d (value next to "%s")',
                $finding['file'],
                $finding['line'],
                $finding['token']
            ),
            $findings
        );
    }

    private static function looksBinary(string $contents): bool
    {
        return str_contains(substr($contents, 0, 8192), "\0");
    }

    /**
     * @return array<int, string>
     */
    private function readLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->fail($path . ' must be readable.');
        }

        return $lines;
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail($path . ' must be readable.');
        }

        return $contents;
    }
}
