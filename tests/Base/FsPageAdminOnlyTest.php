<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/fs_page.php';

/**
 * Minimal in-memory cache double exposing only the `fs_cache` surface that
 * `fs_page` uses, so tests stay hermetic (no CacheManager / filesystem cache).
 */
final class PageCacheStub
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function get_array(string $key): array
    {
        return isset($this->items[$key]) && is_array($this->items[$key]) ? $this->items[$key] : [];
    }

    public function set(string $key, mixed $value, int $ttl = 5400): bool
    {
        $this->items[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }
}

/**
 * Minimal database double recording the SQL executed by `fs_page::save()`.
 */
final class PageDbStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $executedSql = [];

    public function select(string $sql, array $params = []): array|false
    {
        return $this->selectResult;
    }

    public function exec(string $sql, mixed $transaction = null, array $params = [], bool $batch = false): bool
    {
        $this->executedSql[] = $sql;
        return true;
    }

    public function var2str(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return "'" . addslashes((string) $value) . "'";
    }
}

/**
 * PA-02 / PA-03 / PA-10: the persisted `admin_only` mirror on `fs_pages`.
 *
 * Note: PA-02's "clone copies the flag" scenario has no meaningful test here.
 * PHP shallow-copies every property before `__clone()` runs, and the production
 * `fs_page::__clone()` writes to a discarded local, so cloning preserves the
 * flag regardless of that method body. Any assertion would only restate PHP
 * semantics; the eventual persistence guarantees are covered by the round-trip
 * and `all()` cases below.
 */
#[CoversClass(\fs_page::class)]
final class FsPageAdminOnlyTest extends TestCase
{
    private mixed $previousCheckedTables;

    private bool $hadCheckedTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep `fs_page` construction hermetic: pre-mark `fs_pages` as checked
        // so `fs_model::check_table()` is never invoked (it needs a database).
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        $this->hadCheckedTables = is_array($current);
        $this->previousCheckedTables = $current;

        $list = $this->hadCheckedTables ? $current : [];
        if (!in_array('fs_pages', $list, true)) {
            $list[] = 'fs_pages';
        }
        $prop->setValue(null, $list);
    }

    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->hadCheckedTables ? $this->previousCheckedTables : null);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makePage(PageDbStub $db, PageCacheStub $cache, array $data = []): \fs_page
    {
        $page = new class($data === [] ? false : $data) extends \fs_page {
        };

        foreach (['db' => $db, 'cache' => $cache] as $property => $value) {
            $prop = new \ReflectionProperty(\fs_model::class, $property);
            $prop->setAccessible(true);
            $prop->setValue($page, $value);
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function pageData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test_page',
            'title' => 'Test page',
            'folder' => 'admin',
            'show_on_menu' => true,
            'important' => false,
            'orden' => 100,
        ], $overrides);
    }

    #[Test]
    public function defaultIsFalseWhenNoAdminOnlyKeyIsPresent(): void
    {
        $page = $this->makePage(new PageDbStub(), new PageCacheStub(), self::pageData());

        self::assertFalse($page->admin_only, 'A page created without a value must default to false.');
    }

    #[Test]
    public function roundTripReadsTrueFromPersistedValue(): void
    {
        $page = $this->makePage(
            new PageDbStub(),
            new PageCacheStub(),
            self::pageData(['admin_only' => true])
        );

        self::assertTrue($page->admin_only, 'A page created with admin_only = true must read back true.');
    }

    #[Test]
    public function insertSqlPersistsTheFlag(): void
    {
        $db = new PageDbStub();
        $page = $this->makePage(
            $db,
            new PageCacheStub(),
            self::pageData(['admin_only' => true])
        );

        self::assertTrue($page->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('admin_only', $db->executedSql[0]);
        self::assertStringContainsString('TRUE', $db->executedSql[0]);
    }

    #[Test]
    public function updateSqlPersistsTheFlag(): void
    {
        $db = new PageDbStub();
        $db->selectResult = [['name' => 'test_page']];
        $page = $this->makePage(
            $db,
            new PageCacheStub(),
            self::pageData(['admin_only' => false])
        );

        self::assertTrue($page->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('UPDATE', $db->executedSql[0]);
        self::assertStringContainsString('admin_only', $db->executedSql[0]);
        self::assertStringContainsString('FALSE', $db->executedSql[0]);
    }

    #[Test]
    public function allExposesTheFlagForMixedRows(): void
    {
        $db = new PageDbStub();
        $db->selectResult = [
            self::pageData(['name' => 'admin_users', 'admin_only' => true]),
            self::pageData(['name' => 'ventas', 'admin_only' => false]),
        ];

        $page = $this->makePage($db, new PageCacheStub());
        $pages = $page->all();

        self::assertCount(2, $pages);
        $byName = [];
        foreach ($pages as $item) {
            $byName[$item->name] = $item;
        }

        self::assertArrayHasKey('admin_users', $byName);
        self::assertArrayHasKey('ventas', $byName);
        self::assertTrue($byName['admin_users']->admin_only);
        self::assertFalse($byName['ventas']->admin_only);
    }

    /**
     * @return array<string, array{bool, mixed, bool}>
     */
    public static function adminOnlyTruthTable(): array
    {
        return [
            'attribute only' => [true, null, true],
            'attribute overrides false page data' => [true, false, true],
            'attribute overrides string false' => [true, 'false', true],
            'page data alone escalates' => [false, true, true],
            'page data string true escalates' => [false, '1', true],
            'both false' => [false, false, false],
            'both absent' => [false, null, false],
            'legacy row without value' => [false, null, false],
        ];
    }

    #[Test]
    #[DataProvider('adminOnlyTruthTable')]
    public function resolveAdminOnlyOrEscalates(bool $attribute, mixed $pageData, bool $expected): void
    {
        self::assertSame($expected, \fs_page::resolve_admin_only($attribute, $pageData));
    }
}
