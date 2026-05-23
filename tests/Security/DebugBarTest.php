<?php

namespace Tests\Security;

use FSFramework\Core\DebugBar;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class DebugBarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        DebugBar::init();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testRenderReturnsEmptyWhenDebugDisabled(): void
    {
        $output = DebugBar::render();
        $this->assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function testShouldRenderReturnsFalseForRemoteIpWhenDebugEnabled(): void
    {
        if (defined('FS_DEBUG') && !FS_DEBUG) {
            $this->markTestSkipped('FS_DEBUG está desactivado en la configuración local.');
        }
        if (!defined('FS_DEBUG')) {
            define('FS_DEBUG', true);
        }

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';

        $this->assertFalse(DebugBar::shouldRender());
    }

    #[RunInSeparateProcess]
    public function testShouldRenderReturnsTrueForLocalIpWhenDebugEnabled(): void
    {
        if (defined('FS_DEBUG') && !FS_DEBUG) {
            $this->markTestSkipped('FS_DEBUG está desactivado en la configuración local.');
        }
        if (!defined('FS_DEBUG')) {
            define('FS_DEBUG', true);
        }

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->assertTrue(DebugBar::shouldRender());
    }

    public function testAddQueryStoresSqlStatements(): void
    {
        $ref = new \ReflectionClass(DebugBar::class);
        $prop = $ref->getProperty('queries');
        $prop->setAccessible(true);

        DebugBar::addQuery('SELECT * FROM users', 0.005);
        $queries = $prop->getValue();

        $this->assertCount(1, $queries);
        $this->assertSame('SELECT * FROM users', $queries[0]['sql']);
        $this->assertSame(0.005, $queries[0]['duration']);
    }

    public function testAddLogStoresLevelAndMessage(): void
    {
        $ref = new \ReflectionClass(DebugBar::class);
        $prop = $ref->getProperty('logs');
        $prop->setAccessible(true);

        DebugBar::addLog('error', 'Something went wrong');
        $logs = $prop->getValue();

        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0]['level']);
        $this->assertSame('Something went wrong', $logs[0]['message']);
    }

    public function testAddMissingTranslationStoresKey(): void
    {
        $ref = new \ReflectionClass(DebugBar::class);
        $prop = $ref->getProperty('missingTranslations');
        $prop->setAccessible(true);

        DebugBar::addMissingTranslation('login-text');
        $keys = $prop->getValue();

        $this->assertCount(1, $keys);
        $this->assertSame('login-text', $keys[0]);
    }

    public function testToStringReturnsSameAsRender(): void
    {
        $bar = new DebugBar();
        $this->assertSame(DebugBar::render(), (string) $bar);
    }
}
