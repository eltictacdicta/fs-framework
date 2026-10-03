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

namespace Tests\Base;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeIdioma;
use Tests\Support\IdiomaModelFakeDb;

require_once __DIR__ . '/../Support/IdiomaModelFakeDb.php';
require_once __DIR__ . '/../Support/FakeIdioma.php';

/**
 * CI-01…CI-05 — core `idioma` registry invariants.
 *
 * The model is exercised DB-free: a subclass injects the in-memory fake and
 * collects the reported errors, matching the catalogo_core pattern.
 */
final class IdiomaModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/idioma.php';
    }

    // --- CI-01: total deterministic default resolution ---------------------

    public function test_explicit_active_default_wins(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);

        $this->assertSame('es', $this->model($db)->defaultCode());
    }

    public function test_lowest_active_code_is_the_fallback_default(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'pt', 'nombre' => 'Português', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'de', 'nombre' => 'Deutsch', 'activo' => true, 'por_defecto' => false],
        ]);

        $this->assertSame('de', $this->model($db)->defaultCode());
    }

    public function test_inactive_default_flag_is_ignored(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => false, 'por_defecto' => true],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
        ]);

        $this->assertSame('es', $this->model($db)->defaultCode());
    }

    public function test_zero_active_rows_degrade_to_the_constant(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => false, 'por_defecto' => false],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => false, 'por_defecto' => false],
        ]);

        $this->assertSame(\idioma::DEFAULT_CODE, $this->model($db)->defaultCode());
    }

    public function test_resolution_is_permutation_independent(): void
    {
        $first = new IdiomaModelFakeDb([
            ['codidioma' => 'zz', 'nombre' => 'Zeta', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'aa', 'nombre' => 'Alfa', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'mm', 'nombre' => 'Mu', 'activo' => true, 'por_defecto' => false],
        ]);
        $second = new IdiomaModelFakeDb([
            ['codidioma' => 'mm', 'nombre' => 'Mu', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'aa', 'nombre' => 'Alfa', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'zz', 'nombre' => 'Zeta', 'activo' => true, 'por_defecto' => false],
        ]);

        $a = $this->model($first)->defaultCode();
        $b = $this->model($second)->defaultCode();

        $this->assertSame('aa', $a);
        $this->assertSame($a, $b, 'insertion order must not change the resolved default');
    }

    // --- CI-02: active listing (model side) --------------------------------

    public function test_all_activos_excludes_inactive_rows(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => false, 'por_defecto' => false],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);

        $codes = array_map(static fn ($row): string => (string) $row->codidioma, $this->model($db)->all_activos());
        sort($codes, SORT_STRING);

        $this->assertSame(['en', 'es'], $codes);
    }

    // --- CI-03: exactly one active default ---------------------------------

    public function test_set_default_is_a_flag_flip(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->model($db);

        $this->assertTrue($model->set_default('en'));
        $this->assertTrue($db->idiomas['en']['por_defecto'], 'the target must carry the flag');
        $this->assertFalse($db->idiomas['es']['por_defecto'], 'the previous default must be cleared');
        $this->assertSame(['en'], $db->activeDefaults());
        $this->assertSame(1, $db->transactions);
        $this->assertTrue($db->committed);
    }

    public function test_set_default_rejects_unknown_and_inactive_codes(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => false, 'por_defecto' => false],
        ]);
        $model = $this->model($db);

        $this->assertFalse($model->set_default('zz'));
        $this->assertFalse($model->set_default('fr'));
        $this->assertNotEmpty($model->errors);
        $this->assertSame([], $db->executed, 'a rejected set_default must emit no statement');
        $this->assertSame(['es'], $db->activeDefaults());
    }

    public function test_normalize_collapses_duplicate_defaults(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->model($db);

        $before = $model->defaultCode();
        $model->normalize_default();

        $this->assertCount(1, $db->activeDefaults(), 'exactly one active default must remain flagged');
        $this->assertSame($before, $model->defaultCode(), 'normalization must not change the resolved default');
    }

    // --- CI-04: deactivation and deletion guards ---------------------------

    public function test_cannot_deactivate_the_default(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $model = $this->model($db);
        $model->codidioma = 'es';
        $model->nombre = 'Español';
        $model->activo = false;
        $model->por_defecto = true;

        $this->assertFalse($model->save());
        $this->assertNotEmpty($model->errors);
        $this->assertTrue($db->idiomas['es']['activo'], 'the default must stay active');
        $this->assertSame([], $db->executed, 'a rejected deactivation must emit no statement');
    }

    public function test_cannot_delete_the_default(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->model($db);
        $model->codidioma = 'es';
        $model->por_defecto = true;

        $this->assertFalse($model->delete());
        $this->assertArrayHasKey('es', $db->idiomas, 'the default row must still exist');
        $this->assertSame([], $db->executed);
    }

    public function test_cannot_delete_the_last_language(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $model = $this->model($db);
        $model->codidioma = 'es';
        $model->por_defecto = true;

        $this->assertFalse($model->delete());
        $this->assertCount(1, $db->idiomas, 'one row must remain');
        $this->assertSame([], $db->executed);
    }

    public function test_a_non_default_language_can_be_deleted(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->model($db);
        $model->codidioma = 'fr';
        $model->por_defecto = false;

        $this->assertTrue($model->delete());
        $this->assertArrayNotHasKey('fr', $db->idiomas);
        $this->assertArrayHasKey('es', $db->idiomas);
    }

    // --- CI-05: idempotent single-row seed ---------------------------------

    public function test_ensure_defaults_seeds_a_single_es_row_when_empty(): void
    {
        $db = new IdiomaModelFakeDb();
        $model = $this->model($db);

        $model->ensure_defaults();

        $this->assertCount(1, $db->idiomas, 'exactly one row must be seeded');
        $this->assertTrue($db->idiomas['es']['activo']);
        $this->assertTrue($db->idiomas['es']['por_defecto']);
    }

    public function test_ensure_defaults_leaves_a_non_empty_table_unchanged(): void
    {
        $db = new IdiomaModelFakeDb([
            ['codidioma' => 'de', 'nombre' => 'Deutsch', 'activo' => true, 'por_defecto' => false],
        ]);
        $before = $db->idiomas;

        $this->model($db)->ensure_defaults();

        $this->assertSame($before, $db->idiomas, 'a non-empty registry must not be seeded');
        $this->assertArrayNotHasKey('es', $db->idiomas);
    }

    public function test_second_seed_run_is_a_no_op(): void
    {
        $db = new IdiomaModelFakeDb();
        $this->model($db)->ensure_defaults();
        $afterFirst = $db->idiomas;

        $this->model($db)->ensure_defaults();

        $this->assertSame($afterFirst, $db->idiomas, 'a repeated seed must change nothing');
        $this->assertCount(1, $db->idiomas);
    }

    public function test_install_returns_the_single_row_es_seed_sql(): void
    {
        $model = $this->model(new IdiomaModelFakeDb());

        $sql = $model->install();

        $this->assertStringContainsString("'es'", $sql);
        $this->assertStringContainsString(\idioma::TABLE, $sql);
        $this->assertSame(1, substr_count(strtoupper($sql), 'VALUES'), 'install must seed in one INSERT statement');
    }

    // --- validation --------------------------------------------------------

    public function test_validation_bounds(): void
    {
        $model = $this->model(new IdiomaModelFakeDb());

        $model->codidioma = 'x';
        $model->nombre = 'Valido';
        $this->assertFalse($model->test(), 'a codidioma shorter than 2 chars must be rejected');

        $model->codidioma = 'es';
        $model->nombre = str_repeat('a', 51);
        $this->assertFalse($model->test(), 'a nombre longer than 50 chars must be rejected');

        $model->codidioma = 'es';
        $model->nombre = 'Español';
        $this->assertTrue($model->test());
    }

    private function model(IdiomaModelFakeDb $db): FakeIdioma
    {
        return (new FakeIdioma())->useFakeDb($db);
    }
}
