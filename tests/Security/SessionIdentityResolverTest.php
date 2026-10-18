<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Security;

use FSFramework\Security\SecretManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The session identity has ONE implementation.
 *
 * The name used to be computed in four places with two different seeds: the
 * canonical SessionManager seeded with the per-install secret, while
 * base/fs_session_manager.php, base/fs_maintenance_mode.php and
 * base/install_security.php seeded with the install route. The divergence was
 * invisible while FS_SESSION_NAME was set to a literal, because all four
 * returned that literal; without the override (as the core intends) a bootstrap
 * that runs before the Composer autoloader resolved a DIFFERENT name
 * mid-request and the user was bounced to the login page.
 *
 * These tests pin the convergence, and pin the seed chain against
 * SecretManager, which is the authority for the secret.
 */
#[CoversClass(SessionManager::class)]
class SessionIdentityResolverTest extends TestCase
{
    /**
     * Callers that must consume the shared formula instead of re-deriving it.
     */
    private const FORMULA_OWNERS = [
        'base/fs_session_identity.php',
        'base/fs_session_manager.php',
        'base/fs_maintenance_mode.php',
        'base/install_security.php',
        'src/Security/SessionManager.php',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/base/fs_session_identity.php';
    }

    protected function tearDown(): void
    {
        SecretManager::resetCache();
    }

    /**
     * The regression that matters: the dependency-free resolver (the one the
     * pre-Composer bootstraps use) returns exactly what the canonical
     * SessionManager returns, with or without the FS_SESSION_NAME override.
     */
    #[Test]
    public function dependencyFreeResolverAgreesWithTheModernManager(): void
    {
        self::assertSame(
            SessionManager::resolveSessionName(),
            \fs_session_identity_name(),
            'The pre-Composer fallback must resolve the exact same session name as SessionManager'
        );
    }

    /**
     * End-to-end proof of the defect route: in a process WITHOUT the Composer
     * autoloader (the state in which index.php runs the maintenance guard), both
     * legacy fallbacks resolve the exact name the modern SessionManager resolves
     * in this process.
     */
    #[Test]
    public function preAutoloaderFallbacksResolveTheCanonicalName(): void
    {
        $payload = $this->runFallbackProbe();

        self::assertFalse(
            $payload['modern_class_loadable'],
            'The probe must run without the Composer autoloader so the fallback branch is exercised'
        );
        self::assertSame(SessionManager::resolveSessionName(), $payload['identity']);
        self::assertSame(
            SessionManager::resolveSessionName(),
            $payload['legacy_manager'],
            'base/fs_session_manager.php must resolve the canonical name before the autoloader exists'
        );
        self::assertSame(
            SessionManager::resolveSessionName(),
            $payload['maintenance'],
            'base/fs_maintenance_mode.php must resolve the canonical name before the autoloader exists'
        );
    }

    /**
     * Same assertion for the maintenance guard and the legacy facade, which are
     * the two other resolvers: all of them delegate to the shared resolver when
     * the modern class is available and use it directly when it is not.
     */
    #[Test]
    public function maintenanceGuardResolvesTheSameNameAsTheModernManager(): void
    {
        require_once FS_FOLDER . '/base/fs_maintenance_mode.php';

        $resolved = (new \ReflectionMethod(\fs_maintenance_mode::class, 'resolveSessionName'))
            ->invoke(null);

        self::assertSame(SessionManager::resolveSessionName(), $resolved);
    }

    /**
     * With a configured secret the fallback must NOT keep the old
     * install-root seed, which was the stale copy that desynchronized the name.
     */
    #[Test]
    public function configuredSecretRemovesTheLegacyRootSeedDivergence(): void
    {
        if (\fs_session_identity_secret() === null) {
            self::markTestSkipped('No configured secret: the install-root fallback is the documented behaviour.');
        }

        self::assertNotSame(
            SessionManager::legacySessionName(),
            \fs_session_identity_name(),
            'The fallback would desynchronize the session name if it still seeded with the install root'
        );
    }

    /**
     * The seed chain starts at the FS_SECRET_KEY constant, which is what this
     * installation and the test bootstrap configure.
     */
    #[Test]
    public function constantSecretDrivesTheSameSeedAsTheSecretManager(): void
    {
        // Statically true because the bootstrap defines it, but it guards the
        // runtime contract of this suite against a bootstrap regression.
        // @phpstan-ignore-next-line method.alreadyNarrowedType
        $this->assertTrue(defined('FS_SECRET_KEY'), 'tests/bootstrap.php must define FS_SECRET_KEY');

        $expected = trim((string) constant('FS_SECRET_KEY'));

        self::assertSame($expected, \fs_session_identity_secret());
        self::assertSame($expected, SecretManager::getSecret());
        self::assertSame('session:' . $expected, \fs_session_identity_seed());
        self::assertSame(SessionManager::installSeed(), \fs_session_identity_seed());
    }

    /**
     * Constant before environment: both chains agree on the precedence, and the
     * mutated environment is restored.
     */
    #[Test]
    public function constantTakesPrecedenceOverTheEnvironmentInBothChains(): void
    {
        $previous = getenv('FS_SECRET_KEY');

        putenv('FS_SECRET_KEY=' . str_repeat('a', 64));

        try {
            SecretManager::resetCache();

            $expected = trim((string) constant('FS_SECRET_KEY'));

            self::assertSame($expected, \fs_session_identity_secret());
            self::assertSame($expected, SecretManager::getSecret());
        } finally {
            if ($previous === false) {
                putenv('FS_SECRET_KEY');
            } else {
                putenv('FS_SECRET_KEY=' . $previous);
            }

            SecretManager::resetCache();
        }

        self::assertSame($previous, getenv('FS_SECRET_KEY'));
    }

    /**
     * Environment source alone (no constant): both chains resolve the very same
     * secret and the very same session name, and neither writes a secret file.
     */
    #[Test]
    public function environmentSecretChainMatchesTheSecretManager(): void
    {
        $envSecret = str_repeat('e', 64);
        $workDir = $this->makeTempDir('fsframework_session_identity_env_');

        try {
            $payload = $this->runIsolatedProbe($workDir, 'putenv("FS_SECRET_KEY=' . $envSecret . '");');

            self::assertSame($envSecret, $payload['secret'], 'Env secret must resolve in the dependency-free chain');
            self::assertSame($envSecret, $payload['secret_manager'], 'SecretManager must resolve the same env secret');
            self::assertSame('session:' . $envSecret, $payload['seed']);
            self::assertSame($payload['name'], $payload['manager_name'], 'Both chains must agree on the name');
            self::assertSame(
                \fs_session_identity_name_from_seed('session:' . $envSecret),
                $payload['name']
            );
            self::assertSame([], $this->tempDirEntries($workDir), 'No secret file may be created or persisted');
        } finally {
            $this->removeTempDir($workDir);
        }
    }

    /**
     * Secret-file source, including the comment-skipping line parsing that
     * SecretManager performs. The file here is a throwaway fixture inside the
     * system temp directory: the installation's own secret is never touched, and
     * the fixture content must be byte-identical after the run.
     */
    #[Test]
    public function secretFileChainMatchesTheSecretManagerAndIsNeverWritten(): void
    {
        $fileSecret = str_repeat('f', 64);
        $workDir = $this->makeTempDir('fsframework_session_identity_file_');
        $secretFile = $workDir . '/.fs_secret_key';
        $contents = "# FSFramework Secret Key (test fixture)\n// ignored\n; ignored\n" . $fileSecret . "\n";

        file_put_contents($secretFile, $contents);
        $before = (string) sha1_file($secretFile);

        try {
            $payload = $this->runIsolatedProbe($workDir);

            self::assertSame($fileSecret, $payload['secret'], 'The last non-comment line is the secret');
            self::assertSame($fileSecret, $payload['secret_manager']);
            self::assertSame('session:' . $fileSecret, $payload['seed']);
            self::assertSame($payload['name'], $payload['manager_name']);
            self::assertSame(\fs_session_identity_name_from_seed('session:' . $fileSecret), $payload['name']);
            self::assertSame($before, (string) sha1_file($secretFile), 'The secret file must not be rewritten');
        } finally {
            $this->removeTempDir($workDir);
        }
    }

    /**
     * A short environment secret is rejected by the dependency-free chain
     * exactly as SecretManager rejects it: the chain degrades to the install
     * root instead of seeding with a weak value.
     */
    #[Test]
    public function shortEnvironmentSecretIsRejectedByBothChains(): void
    {
        $workDir = $this->makeTempDir('fsframework_session_identity_short_');
        chmod($workDir, 0555);

        try {
            $payload = $this->runIsolatedProbe($workDir, 'putenv("FS_SECRET_KEY=short-secret");');

            self::assertNull($payload['secret'], 'A secret shorter than 32 characters must be rejected');
            self::assertSame($payload['root'], $payload['seed'], 'Without a valid secret the chain degrades to the install root');
            self::assertSame('MissingSecretKeyException', $payload['secret_manager_error']);
            self::assertNull($payload['secret_manager']);
        } finally {
            chmod($workDir, 0750);
            $this->removeTempDir($workDir);
        }
    }

    /**
     * Regression guard against re-duplicating the formula: only the shared
     * resolver may carry it.
     */
    #[Test]
    public function theSessionNameFormulaExistsInExactlyOneFile(): void
    {
        $carriers = [];

        foreach (self::FORMULA_OWNERS as $relativePath) {
            $codeOnly = $this->stripComments((string) file_get_contents(FS_FOLDER . '/' . $relativePath));

            if (str_contains($codeOnly, 'substr(sha1(')) {
                $carriers[] = $relativePath;
            }
        }

        self::assertSame(
            ['base/fs_session_identity.php'],
            $carriers,
            'The session name formula must live in base/fs_session_identity.php only'
        );
    }

    /**
     * The signature helpers mirror their canonical counterparts exactly,
     * including the pre-existing asymmetry: installSignature() hashes the raw
     * seed while legacyInstallSignature() normalizes the route.
     */
    #[Test]
    public function signatureHelpersMirrorTheCanonicalSignatures(): void
    {
        self::assertSame(
            SessionManager::installSignature(),
            \fs_session_identity_install_signature(),
            'installSignature() must stay the RAW seed hash'
        );
        self::assertSame(
            SessionManager::legacyInstallSignature(),
            \fs_session_identity_legacy_install_signature()
        );
        self::assertSame(
            SessionManager::legacySessionName(),
            \fs_session_identity_legacy_name()
        );
    }

    /**
     * The installer keeps its own prefix on top of the same seed: it must never
     * end up sharing a cookie (and therefore a session) with the application,
     * not even when FS_SESSION_NAME is set.
     */
    #[Test]
    public function installerSessionNameSharesTheSeedButNotTheName(): void
    {
        require_once FS_FOLDER . '/base/install_security.php';

        $installerName = \fs_install_resolve_session_name();

        self::assertStringStartsWith('FSINSTALL_', $installerName);
        self::assertSame(
            \fs_session_identity_name_with_prefix('FSINSTALL_', \fs_session_identity_seed()),
            $installerName,
            'The installer shares the application seed but applies its own prefix'
        );
        self::assertNotSame(
            SessionManager::resolveSessionName(),
            $installerName,
            'The installer must never resolve the application session name'
        );

        if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
            self::assertNotSame(
                trim((string) FS_SESSION_NAME),
                $installerName,
                'FS_SESSION_NAME must not hand the application cookie to the installer'
            );
        }
    }

    /**
     * Runs a probe in a fresh PHP process where the constant cannot interfere,
     * so the environment and secret-file branches of the chain are reachable.
     *
     * @return array<string, mixed>
     */
    private function runIsolatedProbe(string $fsFolder, string $envSetup = ''): array
    {
        $probe = <<<'PHP'
putenv('FS_SECRET_KEY');
unset($_ENV['FS_SECRET_KEY'], $_SERVER['FS_SECRET_KEY']);
__ENV_SETUP__
define('FS_FOLDER', __FS_FOLDER__);
require __AUTOLOAD__;
require __IDENTITY__;
$payload = [
    'secret' => fs_session_identity_secret(),
    'seed' => fs_session_identity_seed(),
    'root' => fs_session_identity_install_root(),
    'name' => fs_session_identity_name(),
    'installer_name' => fs_session_identity_installer_name(),
    'legacy_name' => fs_session_identity_legacy_name(),
    'secret_manager' => null,
    'secret_manager_error' => null,
];
try {
    $payload['secret_manager'] = \FSFramework\Security\SecretManager::getSecret();
} catch (\Throwable $e) {
    $payload['secret_manager_error'] = (new \ReflectionClass($e))->getShortName();
}
$payload['manager_name'] = \FSFramework\Security\SessionManager::resolveSessionName();
fwrite(STDOUT, "\n__PAYLOAD__" . json_encode($payload) . "__PAYLOAD__\n");
PHP;

        $probe = str_replace(
            ['__ENV_SETUP__', '__FS_FOLDER__', '__AUTOLOAD__', '__IDENTITY__'],
            [
                $envSetup,
                var_export($fsFolder, true),
                var_export(FS_FOLDER . '/vendor/autoload.php', true),
                var_export(FS_FOLDER . '/base/fs_session_identity.php', true),
            ],
            $probe
        );

        return $this->execProbe($probe);
    }

    /**
     * Resolves the session name the way the pre-Composer bootstraps do: no
     * autoloader, so class_exists() is false and the fallback branch runs.
     *
     * @return array<string, mixed>
     */
    private function runFallbackProbe(): array
    {
        $probe = <<<'PHP'
putenv('FS_SECRET_KEY');
unset($_ENV['FS_SECRET_KEY'], $_SERVER['FS_SECRET_KEY']);
define('FS_FOLDER', __FS_FOLDER__);
define('FS_SECRET_KEY', __SECRET_KEY__);
require __IDENTITY__;
require __LEGACY_MANAGER__;
require __MAINTENANCE__;
$payload = [
    'modern_class_loadable' => class_exists('FSFramework\Security\SessionManager'),
    'identity' => fs_session_identity_name(),
    'legacy_manager' => (new ReflectionMethod('fs_session_manager', 'resolveSessionName'))->invoke(null),
    'maintenance' => (new ReflectionMethod('fs_maintenance_mode', 'resolveSessionName'))->invoke(null),
];
fwrite(STDOUT, "\n__PAYLOAD__" . json_encode($payload) . "__PAYLOAD__\n");
PHP;

        $probe = str_replace(
            ['__FS_FOLDER__', '__SECRET_KEY__', '__IDENTITY__', '__LEGACY_MANAGER__', '__MAINTENANCE__'],
            [
                var_export(FS_FOLDER, true),
                var_export((string) constant('FS_SECRET_KEY'), true),
                var_export(FS_FOLDER . '/base/fs_session_identity.php', true),
                var_export(FS_FOLDER . '/base/fs_session_manager.php', true),
                var_export(FS_FOLDER . '/base/fs_maintenance_mode.php', true),
            ],
            $probe
        );

        return $this->execProbe($probe);
    }

    /**
     * @return array<string, mixed>
     */
    private function execProbe(string $probe): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $output, $exitCode);

        $raw = implode("\n", $output);

        self::assertSame(0, $exitCode, 'Isolated probe failed: ' . $raw);
        self::assertSame(
            1,
            preg_match('/__PAYLOAD__(.*?)__PAYLOAD__/s', $raw, $matches),
            'Isolated probe did not emit its payload: ' . $raw
        );

        $payload = json_decode($matches[1], true);

        self::assertIsArray($payload, 'Isolated probe output is not JSON: ' . $raw);

        return $payload;
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid();

        self::assertTrue(mkdir($dir, 0755, true), 'Could not create the probe directory');

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function tempDirEntries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = @scandir($dir);

        return array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));
    }

    private function removeTempDir(string $dir): void
    {
        foreach ($this->tempDirEntries($dir) as $entry) {
            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeTempDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Leaves code only: no line or block comments.
     */
    private function stripComments(string $source): string
    {
        $withoutBlocks = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*(//|\*).*$#m', '', $withoutBlocks);
    }
}
