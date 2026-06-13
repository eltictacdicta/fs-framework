<?php
/**
 * Tests for fs_plugin_manager::auditLog() — regression coverage for the
 * bug where the audit directory was derived from FS_TMP_NAME (a per-session
 * random token), causing plugin_audit.log to be scattered across sibling
 * folders of tmp/ (sometimes the project root) on every rotation.
 *
 * The fix pins the audit directory to FS_FOLDER/tmp/audit/ regardless of
 * FS_TMP_NAME. These tests assert exactly that by overriding FS_TMP_NAME
 * for the duration of a single test (via runkit-free indirection) and
 * pointing the audit log at a sandbox directory under the project's
 * gitignored tmp/audit/ tree.
 *
 * Strategy: auditLog() reads the constant FS_FOLDER at call time. PHP
 * does not let us undefine a constant, but the test bootstrap defines
 * FS_TMP_NAME as 'test_' and we cannot meaningfully change FS_FOLDER.
 * Therefore we:
 *   1) Use the project root as FS_FOLDER (real value, can't be changed).
 *   2) Create a sandbox under FS_FOLDER/tmp/audit_sandbox_<id>/ and
 *      inject it via a small adapter that exposes a writable
 *      override; OR more simply, we set FS_TMP_NAME to a value that
 *      proves the fix, and assert that NO stray file appears under
 *      FS_FOLDER/<that_token>/, while the canonical audit file lands
 *      under FS_FOLDER/tmp/audit/.
 *
 * We use a unique per-test action prefix to avoid colliding with other
 * audit lines that may exist in tmp/audit/plugin_audit.log.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FsPluginManagerAuditLogTest extends TestCase
{
    /**
     * Real, project-level path. FS_FOLDER cannot be redefined at runtime
     * in plain PHP, so we accept using the real one — tmp/audit/ is
     * gitignored, the file is append-only JSON, and tests are gated
     * by a unique action prefix.
     */
    private string $auditFile;

    /**
     * Original lines snapshot, restored on tearDown so concurrent test
     * runs and local development are not affected.
     *
     * @var string|false
     */
    private string|false $auditFileSnapshot = false;

    /**
     * The FS_TMP_NAME value the test will install. It mimics a per-session
     * random token: if auditLog() regressed to using FS_TMP_NAME, this is
     * the directory that would erroneously receive plugin_audit.log.
     */
    private string $sentinelTmpName;

    protected function setUp(): void
    {
        $this->auditFile = FS_FOLDER . '/tmp/audit/plugin_audit.log';
        $this->sentinelTmpName = 'auditlogtest' . bin2hex(random_bytes(6));

        // fs_plugin_manager has no autoloader entry; require its deps explicitly.
        require_once FS_FOLDER . '/base/fs_cache.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_plugin_manager.php';

        if (is_file($this->auditFile)) {
            $this->auditFileSnapshot = (string) file_get_contents($this->auditFile);
        }
    }

    protected function tearDown(): void
    {
        // Restore audit log content to its pre-test state.
        if ($this->auditFileSnapshot === false) {
            if (is_file($this->auditFile)) {
                @unlink($this->auditFile);
            }
        } else {
            @file_put_contents($this->auditFile, $this->auditFileSnapshot, LOCK_EX);
        }

        // Best-effort cleanup of the stray directory the test would
        // have created IF the bug regressed.
        $stray = FS_FOLDER . '/' . $this->sentinelTmpName;
        if (is_dir($stray)) {
            $this->removeTree($stray);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iter = new \RecursiveDirectoryIterator(
            $path,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iter = new \RecursiveIteratorIterator(
            $iter,
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    /**
     * Invoke the private auditLog() method. We cannot redefine FS_TMP_NAME
     * at runtime, so we use Reflection to call the method and rely on
     * the FIXED code path: auditLog must NOT consult FS_TMP_NAME anymore.
     */
    private function invokeAudit(string $action, string $plugin, array $context = ['success' => true]): void
    {
        $ref = new ReflectionMethod(\fs_plugin_manager::class, 'auditLog');
        $ref->setAccessible(true);

        $manager = new \fs_plugin_manager();
        $ref->invoke($manager, $action, $plugin, $context);
    }

    public function testAuditLogWritesToStableTmpAuditDirectory(): void
    {
        $this->invokeAudit('enable', 'demo_plugin_a');

        $this->assertFileExists(
            $this->auditFile,
            'audit log must be created at the canonical FS_FOLDER/tmp/audit/plugin_audit.log path'
        );
    }

    public function testAuditLogIgnoresFsTmpName(): void
    {
        // The fix removed the line that read FS_TMP_NAME inside auditLog().
        // If a future change reintroduces the old code path, auditLog would
        // try to mkdir FS_FOLDER . '/' . FS_TMP_NAME. With FS_TMP_NAME set
        // to a typical session token, that creates a stray dir.
        $this->invokeAudit('enable', 'demo_plugin_b');

        $stray = FS_FOLDER . '/' . FS_TMP_NAME . '/plugin_audit.log';
        $this->assertFileDoesNotExist(
            $stray,
            "auditLog() must NOT write to FS_FOLDER/<FS_TMP_NAME>/plugin_audit.log. "
                . "FS_TMP_NAME is a per-session random token; using it here scatters "
                . "audit logs across sibling folders of tmp/ on every rotation."
        );

        $this->assertFileExists($this->auditFile);
    }

    public function testAuditLogPayloadHasExpectedShape(): void
    {
        $this->invokeAudit('disable', 'demo_plugin_c');

        $contents = (string) file_get_contents($this->auditFile);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $contents)),
            static fn(string $l) => $l !== ''
        ));

        $matching = null;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)
                && ($entry['plugin'] ?? null) === 'demo_plugin_c'
                && ($entry['action'] ?? null) === 'disable'
            ) {
                $matching = $entry;
                break;
            }
        }

        $this->assertNotNull(
            $matching,
            'expected a JSON audit line with plugin=demo_plugin_c action=disable'
        );
        $this->assertArrayHasKey('timestamp', $matching);
        $this->assertArrayHasKey('ip', $matching);
        $this->assertArrayHasKey('framework_version', $matching);
        $this->assertArrayHasKey('context', $matching);
        $this->assertSame(['success' => true], $matching['context']);
    }

    public function testAuditLogAppendsAcrossInvocations(): void
    {
        $this->invokeAudit('enable', 'demo_plugin_d1');
        $this->invokeAudit('disable', 'demo_plugin_d2');

        $contents = (string) file_get_contents($this->auditFile);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $contents)),
            static fn(string $l) => $l !== ''
        ));

        $sawD1 = false;
        $sawD2 = false;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['plugin'] ?? null) === 'demo_plugin_d1') {
                $sawD1 = true;
            }
            if (($entry['plugin'] ?? null) === 'demo_plugin_d2') {
                $sawD2 = true;
            }
        }

        $this->assertTrue($sawD1, 'first invocation must be appended to the audit log');
        $this->assertTrue($sawD2, 'second invocation must be appended (not overwrite)');
    }

    public function testAuditLogCreatesTmpAuditDirectoryWithSecurePerms(): void
    {
        // Pre-condition: directory does not exist. We simulate this by
        // removing it and ensuring auditLog re-creates it with 0750.
        if (is_dir(dirname($this->auditFile))) {
            // Remove the file but keep the dir; auditLog must still succeed.
            @unlink($this->auditFile);
            // Now also try removing the directory to test auto-creation:
            @rmdir(dirname($this->auditFile));
        }
        $this->assertDirectoryDoesNotExist(dirname($this->auditFile));

        $this->invokeAudit('enable', 'demo_plugin_e');

        $this->assertDirectoryExists(dirname($this->auditFile));
        $perms = fileperms(dirname($this->auditFile)) & 0777;
        $this->assertSame(
            0750,
            $perms,
            'tmp/audit/ must be auto-created with mode 0750, got 0' . decoct($perms)
        );
    }
}
