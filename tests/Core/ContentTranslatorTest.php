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

use FSFramework\Translation\ContentTranslator;
use FSFramework\Translation\LanguageRegistry;
use FSFramework\Translation\Store\FixedColumnTranslationStore;
use FSFramework\Translation\Store\RowTranslationStore;
use FSFramework\Translation\Store\TranslationStoreInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeIdioma;
use Tests\Support\FakeRowStoreDb;
use Tests\Support\IdiomaModelFakeDb;

require_once FS_FOLDER . '/model/idioma.php';
require_once __DIR__ . '/../Support/IdiomaModelFakeDb.php';
require_once __DIR__ . '/../Support/FakeIdioma.php';
require_once __DIR__ . '/../Support/FakeRowStoreDb.php';

/**
 * CI-06, CI-07 — `ContentTranslator` resolution engine (design D3).
 *
 * The chain is `exact -> language prefix (only when the store opted in) ->
 * configured default -> caller base -> ''`. Every case is DB-free: the registry
 * runs over the S1 `FakeIdioma`/`IdiomaModelFakeDb` seam and the stores run over
 * the S4 in-memory doubles. The engine is asserted read-only (never calls
 * `set`) and request-agnostic (never consults Request/session/cookie).
 */
final class ContentTranslatorTest extends TestCase
{
    private const TABLE = 'demo_translations';
    private const RECORD = 'r1';

    /** @var array<string, ?string> */
    private array $fixedColumns = [];

    // --- CI-06: exact leg ---------------------------------------------------

    public function test_exact_match_wins_over_every_other_leg_for_the_row_store(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'fr_CA', ['title' => 'Bonjour CA']);
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);

        $store = $this->rowStore($db, true);

        $this->assertSame('Bonjour CA', $this->translator('en')->read($store, 'title', 'fr_CA'));
    }

    public function test_exact_match_wins_over_the_prefix_leg_for_the_row_store(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt_BR', ['title' => 'Brasil']);
        $db->seed(self::TABLE, self::RECORD, 'pt', ['title' => 'Portugal']);

        $store = $this->rowStore($db, true);

        $this->assertSame('Brasil', $this->translator('en')->read($store, 'title', 'pt_BR'));
    }

    public function test_exact_match_wins_for_the_fixed_column_store(): void
    {
        // The fixed store maps only language suffixes, so fr_CA reduces to fr;
        // an exact-mapped locale still wins over the configured default en.
        $store = $this->fixedStore(['subtitle_fr' => 'Bonjour', 'subtitle_en' => 'Hello']);

        $this->assertSame('Bonjour', $this->translator('en')->read($store, 'subtitle', 'fr_CA'));
    }

    // --- CI-06: prefix leg --------------------------------------------------

    public function test_prefix_leg_applies_when_the_row_store_opts_in(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt', ['title' => 'Olá']);

        $store = $this->rowStore($db, true);

        $this->assertSame('Olá', $this->translator('en')->read($store, 'title', 'pt_BR'));
    }

    public function test_prefix_leg_applies_for_the_fixed_column_store(): void
    {
        $store = $this->fixedStore(['subtitle_es' => 'Hola']);

        $this->assertSame('Hola', $this->translator('en')->read($store, 'subtitle', 'es_ES'));
    }

    public function test_a_store_without_opt_in_does_not_reduce_the_caller_locale(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'pt', ['title' => 'Olá']);

        // Prefix matching disabled: pt_BR must miss the exact leg, and the
        // configured default en holds no value, so the chain terminates at base.
        $store = $this->rowStore($db, false);

        $this->assertSame('legacy', $this->translator('en')->read($store, 'title', 'pt_BR', 'legacy'));
    }

    // --- CI-06: configured default leg --------------------------------------

    public function test_configured_default_leg_supplies_the_value_when_the_caller_locale_misses(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);

        $store = $this->rowStore($db, true);

        $this->assertSame('Hello', $this->translator('en')->read($store, 'title', 'es'));
    }

    public function test_configured_default_leg_supplies_the_value_for_the_fixed_column_store(): void
    {
        $store = $this->fixedStore(['subtitle_en' => 'Hello']);

        $this->assertSame('Hello', $this->translator('en')->read($store, 'subtitle', 'es'));
    }

    // --- CI-06: base leg and terminal ---------------------------------------

    public function test_caller_supplied_base_leg_returns_the_legacy_text(): void
    {
        $store = $this->rowStore(new FakeRowStoreDb());

        $this->assertSame('legacy text', $this->translator('en')->read($store, 'title', 'es', 'legacy text'));
    }

    public function test_empty_terminal_returns_an_empty_string(): void
    {
        $store = $this->rowStore(new FakeRowStoreDb());

        $this->assertSame('', $this->translator('en')->read($store, 'title', 'es'));
    }

    public function test_nullable_locale_skips_the_caller_and_prefix_legs(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);

        $store = $this->rowStore($db, false);

        $this->assertSame('Hello', $this->translator('en')->read($store, 'title', null, 'legacy'));
    }

    // --- CI-06: two-letter caller does not double-match ---------------------

    public function test_two_letter_caller_resolves_once_without_duplicating_its_prefix(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es', ['title' => 'Hola']);

        $store = $this->rowStore($db, true);

        $this->assertSame(['es'], $store->candidates('es'));
        $this->assertSame('Hola', $this->translator('en')->read($store, 'title', 'es'));
    }

    // --- CI-06: locale matrix for both store kinds --------------------------

    public function test_locale_matrix_resolves_for_the_row_store(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es', ['title' => 'Hola']);
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);
        $db->seed(self::TABLE, self::RECORD, 'fr_CA', ['title' => 'Bonjour CA']);
        $db->seed(self::TABLE, self::RECORD, 'pt', ['title' => 'Olá']);

        $store = $this->rowStore($db, true);
        $translator = $this->translator('en');

        $this->assertSame('Hello', $translator->read($store, 'title', 'en_US'));
        $this->assertSame('Bonjour CA', $translator->read($store, 'title', 'fr_CA'));
        $this->assertSame('Olá', $translator->read($store, 'title', 'pt_BR'));
        $this->assertSame('Hola', $translator->read($store, 'title', 'es'));
        $this->assertSame('Hello', $translator->read($store, 'title', 'en'));
    }

    public function test_locale_matrix_resolves_for_the_fixed_column_store(): void
    {
        $store = $this->fixedStore([
            'subtitle_es' => 'Hola',
            'subtitle_fr' => 'Bonjour',
            'subtitle_en' => 'Hello',
        ]);
        $translator = $this->translator('en');

        $this->assertSame('Hello', $translator->read($store, 'subtitle', 'en_US'));
        $this->assertSame('Bonjour', $translator->read($store, 'subtitle', 'fr_CA'));
        // pt has no column: the chain falls through to the configured default.
        $this->assertSame('Hello', $translator->read($store, 'subtitle', 'pt_BR'));
        $this->assertSame('Hola', $translator->read($store, 'subtitle', 'es'));
        $this->assertSame('Hello', $translator->read($store, 'subtitle', 'en'));
    }

    // --- CI-07: read-only, non-materializing --------------------------------

    public function test_read_never_writes_and_walks_the_candidates_in_order(): void
    {
        $store = new class() implements TranslationStoreInterface {
            /** @var list<string> */
            public array $gets = [];

            public int $sets = 0;

            public function get(string $field, string $locale): ?string
            {
                $this->gets[] = $locale;

                return $locale === 'en' ? 'Default' : null;
            }

            public function set(string $field, string $locale, ?string $value): bool
            {
                $this->sets++;

                return true;
            }

            /**
             * @return list<string>
             */
            public function candidates(string $locale): array
            {
                return ['es_ES', 'es'];
            }
        };

        $result = $this->translator('en')->read($store, 'title', 'es_ES', 'base');

        $this->assertSame('Default', $result);
        $this->assertSame(['es_ES', 'es', 'en'], $store->gets);
        $this->assertSame(0, $store->sets, 'read() must never write to the store');
    }

    public function test_read_leaves_the_store_rows_unchanged(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es_ES', ['title' => 'Hola']);
        $db->seed(self::TABLE, self::RECORD, 'fr_CA', ['title' => 'Bonjour']);
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);
        $store = $this->rowStore($db, true);
        $before = $db->rows(self::TABLE);

        $translator = $this->translator('en');
        $translator->read($store, 'title', 'es_ES');
        $translator->read($store, 'title', 'fr_FR');
        $translator->read($store, 'title', 'pt_BR', 'legacy');
        $translator->read($store, 'title', 'de');

        $this->assertSame($before, $db->rows(self::TABLE));
    }

    public function test_no_fallback_is_materialized_for_an_absent_caller_locale(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'en', ['title' => 'Hello']);
        $store = $this->rowStore($db, true);

        $this->assertSame('Hello', $this->translator('en')->read($store, 'title', 'es'));

        $this->assertSame(0, $db->count(self::TABLE, 'es'), 'No fallback row may be created');
        $this->assertSame('Hello', $db->value(self::TABLE, self::RECORD, 'en', 'title'));
    }

    public function test_explicit_locale_is_used_and_a_request_scoped_locale_is_ignored(): void
    {
        $db = new FakeRowStoreDb();
        $db->seed(self::TABLE, self::RECORD, 'es', ['title' => 'Hola']);
        $db->seed(self::TABLE, self::RECORD, 'fr_FR', ['title' => 'Bonjour']);
        $store = $this->rowStore($db, true);

        $_REQUEST['locale'] = 'fr_FR';
        $_SESSION['locale'] = 'fr_FR';

        try {
            $this->assertSame('Hola', $this->translator('en')->read($store, 'title', 'es'));
        } finally {
            unset($_REQUEST['locale'], $_SESSION['locale']);
        }
    }

    public function test_engine_source_never_consults_request_or_session(): void
    {
        $path = FS_FOLDER . '/src/Translation/ContentTranslator.php';
        $this->assertFileExists($path);
        $source = php_strip_whitespace($path);
        $this->assertNotSame('', $source);

        foreach (['Request', '$_SESSION', '$_COOKIE', '$_REQUEST', 'getLocale', 'setlocale'] as $token) {
            $this->assertStringNotContainsString($token, $source);
        }
    }

    // --- helpers ------------------------------------------------------------

    private function translator(string $default = 'en'): ContentTranslator
    {
        $model = (new FakeIdioma())->useFakeDb(new IdiomaModelFakeDb([
            ['codidioma' => $default, 'nombre' => 'Default', 'activo' => true, 'por_defecto' => true],
        ]));

        return new ContentTranslator(new LanguageRegistry($model));
    }

    private function rowStore(FakeRowStoreDb $db, bool $prefixFallback = false): RowTranslationStore
    {
        return new RowTranslationStore(
            self::TABLE,
            ['title' => 'title'],
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
        $this->fixedColumns = $columns;

        $reader = fn (string $column): ?string => $this->fixedColumns[$column] ?? null;
        $writer = function (string $column, ?string $value): bool {
            $this->fixedColumns[$column] = $value;

            return true;
        };

        return new FixedColumnTranslationStore(
            ['es' => 'subtitle_es', 'fr' => 'subtitle_fr', 'en' => 'subtitle_en'],
            $reader,
            $writer
        );
    }
}
