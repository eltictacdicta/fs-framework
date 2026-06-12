<?php
/**
 * Test de regresión para H1 (audit-2026-06-12).
 *
 * El docblock de fs_model::no_html() debe coincidir con el código real:
 * el código SÍ convierte " → &quot;, y el docblock debe afirmarlo.
 *
 * Decisión de diseño (spec H1, sección 3.3.3, opción A): mantener el
 * comportamiento actual (convertir ") y corregir el docblock.
 */

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

final class NoHtmlDocblockTest extends TestCase
{
    public function testDocblockStatesDoubleQuoteIsConverted(): void
    {
        $reflection = new \ReflectionMethod(\fs_model::class, 'no_html');
        $docComment = $reflection->getDocComment();
        $this->assertNotFalse($docComment, 'no_html() debe tener un docblock');

        // El docblock debe mencionar explícitamente las comillas dobles
        $this->assertStringContainsString('comillas dobles', $docComment,
            'El docblock debe mencionar las comillas dobles explícitamente');

        // El docblock NO debe decir que las comillas dobles NO se convierten
        // (esa frase contradice el código y es la regresión a corregir)
        $this->assertStringNotContainsString('NO se convierten', $docComment,
            'El docblock NO debe decir "NO se convierten" (contradice el código)');

        // Debe afirmar que se convierten (contrato documentado)
        $this->assertStringContainsString('se convierten', $docComment,
            'El docblock debe afirmar que las comillas dobles se convierten');
    }

    public function testNoHtmlCodeConvertsDoubleQuote(): void
    {
        // El código SÍ convierte " → &quot; (debe mantenerse)
        $model = new class extends \fs_model {
            public function __construct() {}
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };

        $this->assertSame('hola &quot; mundo', $model->no_html('hola " mundo'));
        $this->assertSame('it&#39;s', $model->no_html("it's"));
        $this->assertSame('&lt;b&gt;', $model->no_html('<b>'));
    }
}
