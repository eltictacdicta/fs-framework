<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\ClientesFacturacion;

use PHPUnit\Framework\TestCase;

/**
 * Anti-regression assertions for the structural decoupling of
 * catalogo_core from clientes_facturacion.
 *
 * Codifies the contract established by the optional-iva-regularization
 * SDD: catalogo_core no longer carries the dead
 * validateFacturaEjercicio() method, no longer references
 * regularizacion_iva, and no longer lists clientes_facturacion in its
 * fsframework.ini `require` field. The three assertions are textual /
 * structural (not behavioral) on purpose: the contract under
 * verification is the absence of coupling, not the behavior of a
 * deleted code path.
 *
 * See: plugins/clientes_facturacion/openspec/changes/optional-iva-regularization/
 */
class CatalogoCoreDecouplingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // fbase_controller is in the global namespace and is not PSR-4
        // autoloaded; load it explicitly on every test method
        // (processIsolation="true" in phpunit.xml). fbase_controller
        // extends fs_controller, which is also not PSR-4 autoloaded.
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/fbase_controller.php';
    }

    public function testFsframeworkIniRequireIsEmpty(): void
    {
        $contents = file_get_contents(FS_FOLDER . '/plugins/catalogo_core/fsframework.ini');
        $this->assertNotFalse($contents, 'plugins/catalogo_core/fsframework.ini must be readable');

        $matched = preg_match('/^\s*require\s*=\s*"?\s*([^"\r\n]*)\s*"?\s*$/m', $contents, $m);
        $this->assertSame(1, $matched, 'fsframework.ini must contain a parseable `require =` directive');

        $tokens = array_filter(array_map('trim', explode(',', $m[1])), 'strlen');
        $this->assertNotContains(
            'clientes_facturacion',
            $tokens,
            'catalogo_core/fsframework.ini must NOT list clientes_facturacion in its `require` field.'
        );
    }

    public function testFbaseControllerIsRegularizacionIvaFree(): void
    {
        $contents = file_get_contents(FS_FOLDER . '/plugins/catalogo_core/extras/fbase_controller.php');
        $this->assertNotFalse($contents, 'plugins/catalogo_core/extras/fbase_controller.php must be readable');

        $this->assertStringNotContainsString(
            'regularizacion_iva',
            $contents,
            'fbase_controller.php must NOT contain the literal `regularizacion_iva` anywhere (no symbol, no comment, no string literal).'
        );
    }

    public function testFbaseControllerHasNoValidateFacturaEjercicioMethod(): void
    {
        // fbase_controller is in the global namespace; bare FQN required.
        $this->assertFalse(
            method_exists('fbase_controller', 'validateFacturaEjercicio'),
            'fbase_controller MUST NOT declare a `validateFacturaEjercicio` method (deleted by optional-iva-regularization).'
        );
    }
}
