<?php
/**
 * Tests para CacheManager — sistema de caché unificado.
 */

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;
use FSFramework\Cache\CacheManager;

class CacheManagerTest extends TestCase
{
    private CacheManager $cache;

    protected function setUp(): void
    {
        // Reset singleton para cada test
        CacheManager::reset();
        $this->cache = CacheManager::getInstance();
        $this->cache->clear();
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    // =====================================================================
    // Singleton
    // =====================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = CacheManager::getInstance();
        $instance2 = CacheManager::getInstance();
        $this->assertSame($instance1, $instance2);
    }

    // =====================================================================
    // set() / getItem()
    // =====================================================================

    public function testSetAndGetItem(): void
    {
        $this->cache->set('test_key', 'test_value');
        $this->assertSame('test_value', $this->cache->getItem('test_key'));
    }

    public function testGetItemDefaultWhenMissing(): void
    {
        $this->assertNull($this->cache->getItem('nonexistent'));
        $this->assertSame('default', $this->cache->getItem('nonexistent', 'default'));
    }

    public function testSetArray(): void
    {
        $data = ['nombre' => 'test', 'activo' => true];
        $this->cache->set('array_key', $data);
        $this->assertSame($data, $this->cache->getItem('array_key'));
    }

    public function testSetInteger(): void
    {
        $this->cache->set('int_key', 42);
        $this->assertSame(42, $this->cache->getItem('int_key'));
    }

    // =====================================================================
    // has()
    // =====================================================================

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('exists', 'yes');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('does_not_exist'));
    }

    // =====================================================================
    // get() con callback
    // =====================================================================

    public function testGetWithCallbackCachesResult(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        };

        $result1 = $this->cache->get('callback_test', $callback);
        $result2 = $this->cache->get('callback_test', $callback);

        $this->assertSame('computed_value', $result1);
        $this->assertSame('computed_value', $result2);
        // El callback solo se ejecuta una vez
        $this->assertSame(1, $callCount);
    }

    // =====================================================================
    // delete()
    // =====================================================================

    public function testDelete(): void
    {
        $this->cache->set('to_delete', 'value');
        $this->assertTrue($this->cache->has('to_delete'));

        $this->cache->delete('to_delete');
        $this->assertFalse($this->cache->has('to_delete'));
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->set('key1', 'val1');
        $this->cache->set('key2', 'val2');
        $this->cache->set('key3', 'val3');

        $this->cache->deleteMultiple(['key1', 'key2']);

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertTrue($this->cache->has('key3'));
    }

    // =====================================================================
    // clear()
    // =====================================================================

    public function testClearRemovesAll(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->clear();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    // =====================================================================
    // getInfo()
    // =====================================================================

    public function testGetInfoReturnsArray(): void
    {
        $info = $this->cache->getInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('type', $info);
        $this->assertArrayHasKey('adapters', $info);
    }
}
