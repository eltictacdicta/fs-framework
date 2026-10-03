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

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeIdioma;
use Tests\Support\IdiomaModelFakeDb;

require_once __DIR__ . '/../Support/IdiomaModelFakeDb.php';
require_once __DIR__ . '/../Support/FakeIdioma.php';

/**
 * CI-01, CI-02 — `LanguageRegistry` facade over the core `idioma` model (D2).
 *
 * The model stays the single source of truth for the durable invariants; the
 * facade only exposes total default resolution, a deterministic active-code
 * listing and delegating accessors. Every case is DB-free: the S1 `FakeIdioma`
 * + `IdiomaModelFakeDb` seam is reused as-is.
 */
final class LanguageRegistryTest extends TestCase
{
    private const REGISTRY = \FSFramework\Translation\LanguageRegistry::class;

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/idioma.php';
    }

    // --- CI-01: total delegation of defaultCode() --------------------------

    public function test_default_code_delegates_the_active_explicit_default(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);

        $this->assertSame('es', $this->registry($db)->defaultCode());
    }

    public function test_default_code_degrades_to_the_constant_when_no_active_row_exists(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => false, 'por_defecto' => true],
        ]);

        $this->assertSame(\idioma::DEFAULT_CODE, $this->registry($db)->defaultCode());
    }

    // --- CI-02: active-language listing ------------------------------------

    public function test_active_codes_excludes_inactive_rows(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => false, 'por_defecto' => false],
        ]);

        $this->assertSame(['en', 'es'], $this->registry($db)->activeCodes());
    }

    public function test_active_codes_sorts_ascending_regardless_of_model_order(): void
    {
        // The model double deliberately yields an unordered listing. The facade
        // MUST sort by `codidioma` in PHP, never trusting the storage order.
        $model = new class() extends \idioma {
            public function __construct()
            {
                $this->table_name = \idioma::TABLE;
            }

            public function all_activos(): array
            {
                return [
                    $this->row('pt'),
                    $this->row('es'),
                    $this->row('de'),
                ];
            }

            private function row(string $code): \idioma
            {
                $row = new static();
                $row->codidioma = $code;
                $row->nombre = strtoupper($code);
                $row->activo = true;
                $row->por_defecto = false;

                return $row;
            }
        };

        $registry = new (self::REGISTRY)($model);

        $this->assertSame(['de', 'es', 'pt'], $registry->activeCodes());
    }

    public function test_active_codes_repeated_calls_are_identical_on_unchanged_data(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'zz', 'nombre' => 'Zeta', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'aa', 'nombre' => 'Alfa', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'mm', 'nombre' => 'Mu', 'activo' => true, 'por_defecto' => false],
        ]);
        $registry = $this->registry($db);

        $this->assertSame($registry->activeCodes(), $registry->activeCodes());
        $this->assertSame(['aa', 'mm', 'zz'], $registry->activeCodes());
    }

    // --- delegation without side effects -----------------------------------

    public function test_all_delegates_the_full_listing(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => false, 'por_defecto' => false],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);

        $rows = $this->registry($db)->all();

        $this->assertCount(2, $rows);
        $this->assertContainsOnlyInstancesOf(\idioma::class, $rows);
        $this->assertSame(
            ['en', 'es'],
            array_map(static fn (\idioma $row): string => (string) $row->codidioma, $rows)
        );
    }

    public function test_get_returns_the_row_or_null(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $registry = $this->registry($db);

        $row = $registry->get('es');

        $this->assertInstanceOf(\idioma::class, $row);
        $this->assertSame('Español', $row->nombre);
        $this->assertNull($registry->get('zz'));
    }

    public function test_default_row_returns_the_flagged_row_or_null(): void
    {
        $withDefault = $this->registry(new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
        ]));
        $withoutDefault = $this->registry(new IdiomaModelFakeDb([
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
        ]));

        $this->assertSame('es', $withDefault->defaultRow()->codidioma);
        $this->assertNull($withoutDefault->defaultRow());
    }

    public function test_save_delegates_to_the_model(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $registry = $this->registry($db);

        $fr = (new FakeIdioma())->useFakeDb($db);
        $fr->codidioma = 'fr';
        $fr->nombre = 'Français';
        $fr->activo = true;
        $fr->por_defecto = false;

        $this->assertTrue($registry->save($fr));
        $this->assertArrayHasKey('fr', $db->idiomas);
        $this->assertSame('Français', $db->idiomas['fr']['nombre']);
    }

    public function test_set_default_delegates_the_flag_flip(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
        $registry = $this->registry($db);

        $this->assertTrue($registry->setDefault('en'));
        $this->assertSame(['en'], $db->activeDefaults());
    }

    public function test_delete_delegates_to_the_model(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
        ]);
        $registry = $this->registry($db);

        $fr = (new FakeIdioma())->useFakeDb($db);
        $fr->codidioma = 'fr';
        $fr->nombre = 'Français';
        $fr->activo = true;
        $fr->por_defecto = false;

        $this->assertTrue($registry->delete($fr));
        $this->assertArrayNotHasKey('fr', $db->idiomas);
        $this->assertArrayHasKey('es', $db->idiomas);
    }

    public function test_model_exposes_the_injected_seam(): void
    {
        $model = (new FakeIdioma())->useFakeDb(new IdiomaModelFakeDb());
        $registry = new (self::REGISTRY)($model);

        $this->assertSame($model, $registry->model());
    }

    private function registry(IdiomaModelFakeDb $db): object
    {
        return new (self::REGISTRY)((new FakeIdioma())->useFakeDb($db));
    }
}
