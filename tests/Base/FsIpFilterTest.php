<?php
/**
 * Tests para fs_ip_filter — lógica de ban/whitelist por IP.
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FsIpFilterTest extends TestCase
{
    private string $tmpFile;

    /** @var list<string> Buckets created by the namespace cases, removed on tearDown. */
    private array $extraBuckets = [];

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_ip_filter.php';

        // Limpiar archivo de IPs antes de cada test
        $this->tmpFile = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log';
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        $this->extraBuckets = [];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        foreach ($this->extraBuckets as $bucket) {
            if (file_exists($bucket)) {
                unlink($bucket);
            }
        }

        $this->extraBuckets = [];
    }

    // =====================================================================
    // is_banned()
    // =====================================================================

    public function testNewIpIsNotBanned(): void
    {
        $filter = new \fs_ip_filter();
        $this->assertFalse($filter->is_banned('1.2.3.4'));
    }

    public function testIpBannedAfterMaxAttempts(): void
    {
        $filter = new \fs_ip_filter();
        $ip = '10.20.30.40';

        // MAX_ATTEMPTS es 5, se ban después de 5
        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt($ip);
        }

        // Reload para verificar persistencia
        $filter2 = new \fs_ip_filter();
        $this->assertTrue($filter2->is_banned($ip));
    }

    public function testIpNotBannedBelowMaxAttempts(): void
    {
        $filter = new \fs_ip_filter();
        $ip = '10.20.30.41';

        // Solo 3 intentos (menos que MAX_ATTEMPTS=5)
        for ($i = 0; $i < 3; $i++) {
            $filter->set_attempt($ip);
        }

        $filter2 = new \fs_ip_filter();
        $this->assertFalse($filter2->is_banned($ip));
    }

    // =====================================================================
    // in_white_list()
    // =====================================================================

    public function testWhitelistAllowsAllWhenWildcard(): void
    {
        // FS_IP_WHITELIST está definido como '*' en el bootstrap
        $filter = new \fs_ip_filter();
        $this->assertTrue($filter->in_white_list('1.2.3.4'));
        $this->assertTrue($filter->in_white_list('10.0.0.1'));
    }

    // =====================================================================
    // clear()
    // =====================================================================

    public function testClearRemovesAllIps(): void
    {
        $filter = new \fs_ip_filter();

        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt('50.50.50.50');
        }

        $filter->clear();

        $filter2 = new \fs_ip_filter();
        $this->assertFalse($filter2->is_banned('50.50.50.50'));
    }

    // =====================================================================
    // Múltiples IPs
    // =====================================================================

    public function testMultipleIpsTrackedIndependently(): void
    {
        $filter = new \fs_ip_filter();

        // Ban IP1
        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt('1.1.1.1');
        }

        // Solo 1 intento para IP2
        $filter->set_attempt('2.2.2.2');

        $filter2 = new \fs_ip_filter();
        $this->assertTrue($filter2->is_banned('1.1.1.1'));
        $this->assertFalse($filter2->is_banned('2.2.2.2'));
    }

    // =====================================================================
    // Bucket namespacing — LTI-01, LTI-02
    //
    // The authentication lockout bucket is a security control: only
    // credential-submission handlers may write to it. A throttling feature
    // that is not authentication gets its own namespace, and therefore its
    // own state file. The empty namespace MUST keep resolving to
    // `tmp/<FS_TMP_NAME>ip.log` byte-for-byte, because that file holds the
    // live bans of every deployment.
    // =====================================================================

    /**
     * LTI-01 scenario "the authentication bucket path is unchanged".
     *
     * This assertion must hold before AND after the namespace capability:
     * moving the empty-namespace path would reset every live ban.
     */
    public function testTheAuthenticationBucketPathIsUnchanged(): void
    {
        $this->assertSame(
            FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log',
            $this->stateFilePath(new \fs_ip_filter()),
            'the empty namespace must keep resolving to the authentication bucket byte-for-byte'
        );
    }

    /**
     * LTI-01 scenarios "the reset flow uses its own bucket" and
     * "authentication keeps the default bucket".
     */
    public function testANamespacedFilterResolvesToItsOwnBucket(): void
    {
        $authentication = $this->stateFilePath(new \fs_ip_filter());
        $reset = $this->stateFilePath(new \fs_ip_filter('password_reset'));

        $this->assertSame(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'password_reset_ip.log', $reset);
        $this->assertNotSame($authentication, $reset, 'the reset bucket must not be the authentication bucket');
    }

    /**
     * LTI-02 scenario "a hostile namespace resolves inside tmp".
     *
     * A future call site may derive the namespace from a non-literal value, so
     * the sanitisation is the boundary, not the call sites.
     */
    #[DataProvider('provideHostileNamespaces')]
    public function testAHostileNamespaceCannotEscapeTheTemporaryDirectory(string $namespace): void
    {
        $tmpDir = FS_FOLDER . '/tmp/';
        $path = $this->stateFilePath(new \fs_ip_filter($namespace));

        $this->assertStringStartsWith($tmpDir, $path, 'the state file must stay inside tmp/');
        $this->assertSame(
            FS_FOLDER . '/tmp',
            dirname($path),
            'the state file must resolve directly inside tmp/, not in a subdirectory'
        );
        $this->assertStringNotContainsString(
            '/',
            substr($path, strlen($tmpDir)),
            'the sanitised file name must contain no path separator'
        );
    }

    public static function provideHostileNamespaces(): array
    {
        return [
            'mixed case and punctuation' => ['Password-Reset!!'],
            'path traversal' => ['../../etc/passwd'],
            'only punctuation' => ['!!!'],
            'nested traversal' => ['..%2F..%2Fetc'],
        ];
    }

    /**
     * LTI-02 scenario "a sanitised namespace is deterministic".
     */
    public function testASanitisedNamespaceIsDeterministic(): void
    {
        $this->assertSame(
            $this->stateFilePath(new \fs_ip_filter('Password-Reset!!')),
            $this->stateFilePath(new \fs_ip_filter('Password-Reset!!')),
            'the same namespace must always resolve to the same bucket'
        );
    }

    /**
     * LTI-01 scenario "exhausting the reset quota has no authentication
     * consequence" at the storage layer: attempts written through a namespace
     * never reach the authentication bucket.
     */
    public function testANamespacedFilterNeverWritesToTheAuthenticationBucket(): void
    {
        $reset = new \fs_ip_filter('password_reset');
        $this->trackBucket($this->stateFilePath($reset));

        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $reset->set_attempt('198.51.100.7');
        }

        $this->assertTrue((new \fs_ip_filter('password_reset'))->is_banned('198.51.100.7'));
        $this->assertFalse(
            (new \fs_ip_filter())->is_banned('198.51.100.7'),
            'the authentication bucket must not be written by a namespaced filter'
        );
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log',
            'a namespaced filter must not create the authentication bucket'
        );
    }

    /**
     * The resolved state-file path is private by design (LTI-05 "no new public
     * API"); the tests observe it through reflection instead of widening the
     * production surface with a getter.
     */
    private function stateFilePath(\fs_ip_filter $filter): string
    {
        $property = new \ReflectionProperty(\fs_ip_filter::class, 'filePath');
        $property->setAccessible(true);

        return (string) $property->getValue($filter);
    }

    private function trackBucket(string $path): void
    {
        $this->extraBuckets[] = $path;
    }
}
