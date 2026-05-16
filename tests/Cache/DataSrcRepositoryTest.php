<?php

namespace Tests\Cache;

use FSFramework\Cache\CacheManager;
use FSFramework\Cache\DataSrcRepository;
use PHPUnit\Framework\TestCase;

class DataSrcRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CacheManager::reset();
        TestDataSrc::setTestData([]);
    }

    protected function tearDown(): void
    {
        TestDataSrc::reset();
        CacheManager::reset();
        parent::tearDown();
    }

    public function testAllLoadsAndCachesData(): void
    {
        TestDataSrc::setTestData([['id' => 1, 'name' => 'Test 1'], ['id' => 2, 'name' => 'Test 2']]);

        $all = TestDataSrc::all();

        $this->assertCount(2, $all);
        $this->assertSame('Test 1', $all[0]['name']);
    }

    public function testClearInvalidatesMemoryCache(): void
    {
        TestDataSrc::setTestData([['id' => 1, 'name' => 'Before Clear']]);
        TestDataSrc::all(); // Cache it

        TestDataSrc::setTestData([['id' => 99, 'name' => 'After Clear']]);
        TestDataSrc::clear();

        $all = TestDataSrc::all();
        $this->assertSame('After Clear', $all[0]['name']);
    }

    public function testFindByReturnsMatchingRecord(): void
    {
        TestDataSrc::setTestData([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        TestDataSrc::clear();

        $result = TestDataSrc::findBy('name', 'Bob');
        $this->assertNotNull($result);
        $this->assertSame(2, $result['id']);

        $notFound = TestDataSrc::findBy('name', 'Charlie');
        $this->assertNull($notFound);
    }

    public function testCodeModelReturnsKeyValuePairs(): void
    {
        TestDataSrc::setTestData([
            ['id' => 'A', 'name' => 'Alpha'],
            ['id' => 'B', 'name' => 'Beta'],
        ]);
        TestDataSrc::clear();

        $codes = TestDataSrc::codeModel('id', 'name');
        $this->assertCount(3, $codes); // 2 items + empty
        $this->assertSame('Alpha', $codes['A']);
        $this->assertSame('Beta', $codes['B']);
        $this->assertSame('------', $codes['']);
    }

    public function testCodeModelWithoutEmptyOption(): void
    {
        TestDataSrc::setTestData([['id' => 'X', 'name' => 'X-ray']]);
        TestDataSrc::clear();

        $codes = TestDataSrc::codeModel('id', 'name', false);
        $this->assertCount(1, $codes);
        $this->assertArrayNotHasKey('', $codes);
    }

    public function testClearOnEmptyCacheDoesNotError(): void
    {
        TestDataSrc::clear();
        $all = TestDataSrc::all();
        $this->assertSame([], $all);
    }
}

/**
 * Concrete implementation for testing
 */
class TestDataSrc extends DataSrcRepository
{
    protected static string $dataSrcKey = 'test_datasrc';
    private static array $testData = [];

    public static function reset(): void
    {
        self::$testData = [];
        self::clear();
    }

    public static function setTestData(array $data): void
    {
        self::$testData = $data;
        self::clear();
    }

    protected static function loadAll(): array
    {
        return self::$testData;
    }
}
