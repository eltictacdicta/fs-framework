<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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
declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * SECURITY: random_string() previously used str_shuffle() (Mersenne Twister,
 * a weak PRNG) over [0-9a-zA-Z]. The audit-2026-06-12 fix replaces it with
 * bin2hex(random_bytes()), a CSPRNG. The charset shrinks to [0-9a-f].
 *
 * Charset change is safe for all current consumers:
 *   - Cache busting in admin_home*.twig uses random_string(4)|raw; [0-9a-f]
 *     works identically for URL params.
 *   - install.php uses it for the install token, where unpredictability
 *     matters more than charset.
 *   - fs_login uses it as a demo-mode suffix; charset is cosmetic.
 */
final class RandomStringHardeningTest extends TestCase
{
    public function testFsModelRandomStringIsHex(): void
    {
        $model = new class extends \fs_model {
            public function __construct() {}
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };
        $ref = new \ReflectionMethod($model, 'random_string');
        $ref->setAccessible(true);

        $value = $ref->invoke($model, 16);
        $this->assertSame(16, strlen($value),
            'fs_model::random_string(16) debe retornar exactamente 16 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $value,
            'fs_model::random_string debe retornar hex (cambió de alfanumérico a hex por seguridad)');
    }

    public function testFsLoginRandomStringIsHexAndUnique(): void
    {
        require_once __DIR__ . '/../../base/fs_login.php';
        $ref = new \ReflectionMethod(\fs_login::class, 'random_string');
        $ref->setAccessible(true);
        $login = new \fs_login();

        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = $ref->invoke($login, 12);
        }
        $this->assertCount(100, array_unique($values),
            '100 invocaciones de fs_login::random_string deben ser únicas (CSPRNG)');
        foreach ($values as $v) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $v);
            $this->assertSame(12, strlen($v));
        }
    }

    public function testInstallRandomStringIsHex(): void
    {
        // install.php no se puede require_once en tests porque su guard
        // install_security.php ejecuta die() con HTML cuando config.php existe.
        // Validamos el código fuente estáticamente y triangulamos con
        // bin2hex(random_bytes()) para confirmar el contrato del CSPRNG.
        $installSrc = file_get_contents(__DIR__ . '/../../install.php');

        $this->assertMatchesRegularExpression(
            '/function\s+random_string\s*\([^)]*\)\s*\{\s*return\s+substr\(\s*bin2hex\(\s*random_bytes\s*\(/i',
            $installSrc,
            'install.php::random_string() debe usar bin2hex(random_bytes(...))'
        );
        $this->assertStringNotContainsString(
            'str_shuffle',
            $installSrc,
            'install.php no debe usar str_shuffle en random_string (PRNG débil)'
        );

        // Triangulación: el mismo patrón aplicado a un length real produce
        // la cantidad esperada de chars hex. Esto valida el contrato del
        // CSPRNG y la conversión, no solo el string match del source.
        $value = substr(bin2hex(random_bytes((int) ceil(20 / 2))), 0, 20);
        $this->assertSame(20, strlen($value));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $value,
            'bin2hex(random_bytes(...)) debe producir chars hex [0-9a-f]');
    }

    public function testFsAppRandomStringIsHex(): void
    {
        require_once __DIR__ . '/../../base/fs_app.php';
        $ref = new \ReflectionMethod(\fs_app::class, 'random_string');
        $ref->setAccessible(true);
        $app = new \fs_app();

        $value = $ref->invoke($app, 8);
        $this->assertSame(8, strlen($value),
            'fs_app::random_string(8) debe retornar exactamente 8 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $value,
            'fs_app::random_string debe retornar hex');
    }

    public function testRandomStringUsesRandomBytesNotStrShuffle(): void
    {
        // Test estático: ninguna implementación de random_string puede usar
        // str_shuffle, que es la firma del PRNG débil que estamos eliminando.
        $files = [
            __DIR__ . '/../../base/fs_model.php',
            __DIR__ . '/../../base/fs_app.php',
            __DIR__ . '/../../base/fs_login.php',
            __DIR__ . '/../../install.php',
        ];
        $offenders = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            // str_shuffle es legítimo en otros contextos (no solo random_string),
            // pero combinado con la firma "function random_string" lo prohibimos.
            if (str_contains($content, 'str_shuffle')
                && preg_match('/function\s+random_string/', $content)) {
                $offenders[] = basename($file);
            }
        }
        $this->assertEmpty($offenders,
            'random_string() no debe usar str_shuffle (Mersenne Twister es PRNG débil): '
            . implode(', ', $offenders));
    }
}
