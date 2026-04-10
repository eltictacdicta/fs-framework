<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Regression tests for MySQL constraint comparison with renamed constraints.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsMysqlConstraintComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('FS_FOREIGN_KEYS')) {
            define('FS_FOREIGN_KEYS', true);
        }

        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';
    }

    public function testKeepsEquivalentUniqueConstraintWithLegacyIndexName(): void
    {
        $mysql = new class([
            [
                'name' => 'PRIMARY',
                'type' => 'PRIMARY KEY',
                'column_name' => 'id',
                'ordinal_position' => 1,
            ],
            [
                'name' => 'codgrupo',
                'type' => 'UNIQUE',
                'column_name' => 'codgrupo',
                'ordinal_position' => 1,
            ],
        ]) extends \fs_mysql {
            public function __construct(private array $extendedConstraints)
            {
                parent::__construct();
            }

            public function get_constraints_extended($table_name)
            {
                return $this->extendedConstraints;
            }
        };

        $xmlConstraints = [
            ['nombre' => 'tarif_grupo_roles_pkey', 'consulta' => 'PRIMARY KEY (id)'],
            ['nombre' => 'tarif_grupo_roles_codgrupo_unique', 'consulta' => 'UNIQUE (codgrupo)'],
        ];
        $dbConstraints = [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
            ['name' => 'codgrupo', 'type' => 'UNIQUE'],
        ];

        $this->assertSame('', $mysql->compare_constraints('tarif_grupo_roles', $xmlConstraints, $dbConstraints));
    }

    public function testStillRebuildsConstraintsWhenDefinitionDiffers(): void
    {
        $mysql = new class([
            [
                'name' => 'PRIMARY',
                'type' => 'PRIMARY KEY',
                'column_name' => 'id',
                'ordinal_position' => 1,
            ],
            [
                'name' => 'codgrupo',
                'type' => 'UNIQUE',
                'column_name' => 'otro_campo',
                'ordinal_position' => 1,
            ],
        ]) extends \fs_mysql {
            public function __construct(private array $extendedConstraints)
            {
                parent::__construct();
            }

            public function get_constraints_extended($table_name)
            {
                return $this->extendedConstraints;
            }
        };

        $xmlConstraints = [
            ['nombre' => 'tarif_grupo_roles_pkey', 'consulta' => 'PRIMARY KEY (id)'],
            ['nombre' => 'tarif_grupo_roles_codgrupo_unique', 'consulta' => 'UNIQUE (codgrupo)'],
        ];
        $dbConstraints = [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
            ['name' => 'codgrupo', 'type' => 'UNIQUE'],
        ];

        $sql = $mysql->compare_constraints('tarif_grupo_roles', $xmlConstraints, $dbConstraints);

        $this->assertStringContainsString('DROP INDEX codgrupo', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT tarif_grupo_roles_codgrupo_unique UNIQUE (codgrupo)', $sql);
    }
}