<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Translation\Store\FixedColumnTranslationStore;
use FSFramework\Translation\Store\RowTranslationStore;
use FSFramework\Translation\Store\TranslationStoreInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRowStoreDb;

require_once __DIR__ . '/../Support/FakeRowStoreDb.php';

/**
 * CI-08…CI-16 — translation stores (design D3, D4, D5, D6, D12).
 *
 * `candidates()` is the only reduction boundary; `get`/`set` are exact. Every
 * case is DB-free: `FakeRowStoreDb` is an in-memory fs_db2 double keyed by
 * `record|locale`, so it enforces the `UNIQUE(record_key, codidioma)`
 * semantics that CI-12 requires. The two `RowTranslationStore` instances of
 * CI-09 are built over the *same* fake so the opt-in pair differs only by its
 * constructor flag.
 */
final class TranslationStoresTest extends TestCase
{
    private const ROW_STORE = RowTranslationStore::class;
    private const FIXED_STORE = FixedColumnTranslationStore::class;
    private const TABLE = 'demo_translations';
    private const RECORD = 'r1';

    /** @var list<string> */
    private array $readColumns = [];

    // --- CI-08: exact stored code wins, no rewrite -------------------------

    public function test_exact_region_qualified_stored_code_wins_and_is_not_rewritten(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'fr_CA', ['title' => 'Bonjour']);
        $store = $this->rowStore($db);

        $this->assertSame('Bonjour', $store->get('title', 'fr_CA'));
        $this->assertSame('fr_CA', $db->rows(self::TABLE)[0]['locale'], 'Stored code must survive the read');
    }

    public function test_region_qualified_caller_does_not_degrade_without_opt_in(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es', ['title' => 'Hola']);
        $store = $this->rowStore($db);

        $this->assertSame(['es_ES'], $store->candidates('es_ES'));
        $this->assertNull($this->read($store, 'title', 'es_ES'));
    }

    // --- CI-09: per-store prefix opt-in over identical data ----------------

    public function test_prefix_reduction_applies_only_to_the_opted_in_instance(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt', ['title' => 'Olá']);

        $plain = $this->rowStore($db, false);
        $reducing = $this->rowStore($db, true);

        $this->assertNull($this->read($plain, 'title', 'pt_BR'));
        $this->assertSame('Olá', $this->read($reducing, 'title', 'pt_BR'));
        $this->assertSame(1, $db->count(self::TABLE), 'Reads must not materialize rows');
    }

    public function test_candidates_pair_differs_only_by_the_prefix_code(): void
    {
        $db = new FakeRowStoreDb();

        $this->assertSame(['pt_BR'], $this->rowStore($db, false)->candidates('pt_BR'));
        $this->assertSame(['pt_BR', 'pt'], $this->rowStore($db, true)->candidates('pt_BR'));
    }

    public function test_two_letter_caller_does_not_duplicate_its_prefix(): void
    {
        $db = new FakeRowStoreDb();

        $this->assertSame(['es'], $this->rowStore($db, true)->candidates('es'));
    }

    // --- CI-10: no global collapse, distinct regions stay distinct ---------

    public function test_distinct_regional_codes_remain_distinct(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt_BR', ['title' => 'Brasil']);
        $db->seed(self::TABLE, self::RECORD, 'pt_PT', ['title' => 'Portugal']);
        $store = $this->rowStore($db);

        $this->assertSame('Brasil', $store->get('title', 'pt_BR'));
        $this->assertSame('Portugal', $store->get('title', 'pt_PT'));
    }

    public function test_region_caller_is_not_served_a_different_region_code(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt_PT', ['title' => 'Portugal']);

        // Prefix matching disabled: pt_BR must never receive pt_PT's value.
        $this->assertNull($this->read($this->rowStore($db, false), 'title', 'pt_BR'));
    }

    public function test_stored_codes_survive_a_series_of_reads(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es_ES', ['title' => 'Hola']);
        $db->seed(self::TABLE, self::RECORD, 'fr_CA', ['title' => 'Bonjour']);
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);
        $store = $this->rowStore($db, true);

        $this->read($store, 'title', 'es_ES');
        $this->read($store, 'title', 'fr_FR');
        $this->read($store, 'title', 'en_US');

        $this->assertSame(['en', 'es_ES', 'fr_CA'], $db->codes(self::TABLE));
    }

    // --- CI-12: one row per record + locale, update-not-duplicate ----------

    public function test_writing_the_same_pair_updates_and_does_not_duplicate(): void
    {
        $db = new FakeRowStoreDb();
        $store = $this->rowStore($db);

        $this->assertTrue($store->set('title', 'en', 'Hello'));
        $this->assertTrue($store->set('title', 'en', 'Hi'));

        $this->assertSame(1, $db->count(self::TABLE));
        $this->assertSame('Hi', $store->get('title', 'en'));
    }

    public function test_row_store_source_preserves_uniqueness_without_any_ddl(): void
    {
        $source = $this->source('RowTranslationStore.php');

        $this->assertStringNotContainsString('ALTER TABLE', $source);
        $this->assertStringNotContainsString('DROP TABLE', $source);
        $this->assertStringNotContainsString('CREATE TABLE', $source);
        // UNIQUE(record_key, codidioma) is never dropped, renamed or repointed.
        $this->assertStringNotContainsString('DROP CONSTRAINT', $source);
    }

    // --- CI-13: app-level purge, no language FK ----------------------------

    public function test_purge_language_removes_only_that_locale_and_counts(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, 'r1', 'es', ['title' => 'Hola']);
        $db->seed(self::TABLE, 'r2', 'es', ['title' => 'Adiós']);
        $db->seed(self::TABLE, 'r1', 'fr', ['title' => 'Bonjour']);
        $db->seed(self::TABLE, 'r2', 'fr', ['title' => 'Salut']);

        $removed = $this->rowStore($db)->purgeLanguage('fr');

        $this->assertSame(2, $removed);
        $this->assertSame(['es'], $db->codes(self::TABLE));
        $this->assertSame(2, $db->count(self::TABLE, 'es'));
    }

    public function test_purge_language_on_absent_locale_is_a_noop(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es', ['title' => 'Hola']);

        $this->assertSame(0, $this->rowStore($db)->purgeLanguage('de'));
        $this->assertSame(1, $db->count(self::TABLE));
        $this->assertSame('Hola', $db->value(self::TABLE, self::RECORD, 'es', 'title'));
    }

    public function test_row_store_source_adds_no_language_foreign_key(): void
    {
        $source = $this->source('RowTranslationStore.php');

        $this->assertStringNotContainsString('FOREIGN KEY', $source);
        $this->assertStringNotContainsString('REFERENCES', $source);
        $this->assertStringNotContainsString('ON DELETE CASCADE', $source);
    }

    public function test_table_name_exposes_the_injected_table(): void
    {
        $this->assertSame(self::TABLE, $this->rowStore(new FakeRowStoreDb())->tableName());
    }

    // --- CI-14: set(null|'') clears, never copies a fallback ---------------

    public function test_set_null_clears_the_entry(): void
    {
        $db = new FakeRowStoreDb();
        $store = $this->rowStore($db);
        $store->set('title', 'en', 'Hello');

        $this->assertTrue($store->set('title', 'en', null));

        $this->assertNull($store->get('title', 'en'));
        $this->assertSame(0, $db->count(self::TABLE), 'An all-null row must be deleted');
    }

    public function test_set_empty_string_clears_the_entry(): void
    {
        $db = new FakeRowStoreDb();
        $store = $this->rowStore($db);
        $store->set('title', 'fr', 'Bonjour');

        $this->assertTrue($store->set('title', 'fr', ''));

        $this->assertNull($store->get('title', 'fr'));
        $this->assertSame(0, $db->count(self::TABLE));
    }

    public function test_clearing_is_absence_and_does_not_copy_a_fallback(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Default']);
        $store = $this->rowStore($db);
        $store->set('title', 'es', 'Hola');

        $this->assertTrue($store->set('title', 'es', ''));

        $this->assertNull($store->get('title', 'es'));
        $this->assertNull($db->value(self::TABLE, self::RECORD, 'es', 'title'));
        $this->assertSame('Default', $db->value(self::TABLE, self::RECORD, 'en', 'title'));
    }

    public function test_clearing_one_field_preserves_sibling_fields(): void
    {
        $db = new FakeRowStoreDb();
        $store = $this->rowStore($db);
        $store->set('title', 'en', 'Hello');
        $store->set('body', 'en', 'Body');

        $this->assertTrue($store->set('title', 'en', null));

        $this->assertNull($store->get('title', 'en'));
        $this->assertSame('Body', $store->get('body', 'en'));
        $this->assertSame(1, $db->count(self::TABLE), 'The row survives while a sibling field is set');
    }

    // --- CI-15: generic, core-scoped store ---------------------------------

    public function test_store_operates_only_on_its_injected_table(): void
    {
        $db = new FakeRowStoreDb();
        $alpha = new (self::ROW_STORE)('alpha_translations', ['title' => 'title'], 'record_key', self::RECORD, 'codidioma', $db);
        $beta = new (self::ROW_STORE)('beta_translations', ['title' => 'title'], 'record_key', self::RECORD, 'codidioma', $db);

        $alpha->set('title', 'en', 'Alpha');
        $beta->set('title', 'en', 'Beta');

        $this->assertSame('Alpha', $alpha->get('title', 'en'));
        $this->assertSame('Beta', $beta->get('title', 'en'));
        $this->assertSame(1, $db->count('alpha_translations'));
        $this->assertSame(1, $db->count('beta_translations'));
        $this->assertSame([], $db->rows('gamma_translations'));
    }

    public function test_row_store_source_has_no_plugin_identifier(): void
    {
        $source = $this->source('RowTranslationStore.php');

        foreach (['catalogo_idiomas', 'articulo_descripciones', 'catalogo_opcional_idiomas', 'LocaleManager'] as $pluginId) {
            $this->assertStringNotContainsString($pluginId, $source);
        }
    }

    // --- CI-11 + CI-16: fixed-column store ---------------------------------

    public function test_fixed_column_read_maps_locale_to_column_via_candidates(): void
    {
        $store = $this->fixedStore(['subtitle_es' => 'Hola', 'subtitle_fr' => 'Bonjour', 'subtitle_en' => null]);

        $this->assertSame(['fr_FR', 'fr'], $store->candidates('fr_FR'));
        $this->assertSame('Bonjour', $this->read($store, 'subtitle', 'fr_FR'));
    }

    public function test_fixed_column_write_maps_to_a_single_column(): void
    {
        $store = $this->fixedStore(['subtitle_es' => 'Hola', 'subtitle_fr' => 'Bonjour', 'subtitle_en' => null]);

        $this->assertTrue($store->set('subtitle', 'en', 'Hello'));

        $this->assertSame('Hello', $store->get('subtitle', 'en'));
        $this->assertSame('Hola', $store->get('subtitle', 'es'), 'No other column may be modified');
        $this->assertSame('Bonjour', $store->get('subtitle', 'fr'));
    }

    public function test_fixed_column_store_does_not_apply_a_plugin_alias(): void
    {
        $store = $this->fixedStore(['subtitle_es' => 'Hola', 'subtitle_fr' => 'Bonjour', 'subtitle_en' => 'English']);

        // en_US has no column of its own; the mechanical suffix reduction reads
        // subtitle_en. The core must never redirect en_US to a plugin alias.
        $this->assertSame(['en_US', 'en'], $store->candidates('en_US'));
        $this->assertSame('English', $this->read($store, 'subtitle', 'en_US'));
        $this->assertSame(['subtitle_en'], $this->readColumns);
    }

    public function test_fixed_column_source_contains_no_alias_and_no_ddl(): void
    {
        $source = $this->source('FixedColumnTranslationStore.php');

        $this->assertStringNotContainsString('en_GB', $source);
        $this->assertStringNotContainsString('ALTER TABLE', $source);
        $this->assertStringNotContainsString('CREATE TABLE', $source);
    }

    public function test_fixed_column_repeat_caller_reads_the_same_column_as_normalized_caller(): void
    {
        $store = $this->fixedStore(['subtitle_en' => 'English']);

        // A plugin that already normalized en_US -> en_GB expects the en column;
        // the core's mechanical reduction yields the same column for both.
        $this->assertSame('English', $this->read($store, 'subtitle', 'en_GB'));
        $this->assertSame('English', $this->read($store, 'subtitle', 'en_US'));
    }

    // --- contract -----------------------------------------------------------

    public function test_both_stores_implement_the_contract(): void
    {
        $this->assertInstanceOf(TranslationStoreInterface::class, $this->rowStore(new FakeRowStoreDb()));
        $this->assertInstanceOf(TranslationStoreInterface::class, $this->fixedStore(['es' => 'subtitle_es']));
    }

    // --- helpers ------------------------------------------------------------

    private function rowStore(FakeRowStoreDb $db, bool $prefixFallback = false): RowTranslationStore
    {
        return new (self::ROW_STORE)(
            self::TABLE,
            ['title' => 'title', 'body' => 'body'],
            'record_key',
            self::RECORD,
            'codidioma',
            $db,
            $prefixFallback
        );
    }

    /**
     * @param array<string, ?string> $columns
     */
    private function fixedStore(array $columns): FixedColumnTranslationStore
    {
        $this->readColumns = [];
        $reader = function (string $column): ?string {
            $this->readColumns[] = $column;

            return $this->fixedColumns[$column] ?? null;
        };
        $writer = function (string $column, ?string $value): bool {
            $this->fixedColumns[$column] = $value;

            return true;
        };
        $this->fixedColumns = $columns;

        return new (self::FIXED_STORE)(
            ['es' => 'subtitle_es', 'fr' => 'subtitle_fr', 'en' => 'subtitle_en'],
            $reader,
            $writer
        );
    }

    /** @var array<string, ?string> */
    private array $fixedColumns = [];

    /**
     * Mirror of the engine's store legs (S5 owns the full chain): walk the
     * declared candidates in order and return the first exact hit.
     */
    private function read(TranslationStoreInterface $store, string $field, string $locale): ?string
    {
        foreach ($store->candidates($locale) as $candidate) {
            $value = $store->get($field, $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function source(string $file): string
    {
        $path = FS_FOLDER . '/src/Translation/Store/' . $file;
        $this->assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw, 'Source inspection must read real content');

        // Strip comments so the structural gates assert real code, never prose.
        return php_strip_whitespace($path);
    }
}
