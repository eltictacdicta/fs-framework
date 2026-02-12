<?php
/**
 * Tests para los métodos puros de fs_model que no requieren DB.
 *
 * Se usa ReflectionClass para invocar los métodos directamente en una
 * instancia sin pasar por el constructor (que requiere conexión a DB).
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsModelMethodsTest extends TestCase
{
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        // Crear subclase concreta con constructor vacío (evita la conexión a DB)
        $this->model = new class() extends \fs_model {
            public function __construct() {
                // No llamar al constructor padre — evita DB/cache
            }
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };
    }

    // =====================================================================
    // no_html()
    // =====================================================================

    public function testNoHtmlEscapesAngleBrackets(): void
    {
        $this->assertSame('&lt;script&gt;', $this->model->no_html('<script>'));
    }

    public function testNoHtmlEscapesQuotes(): void
    {
        $this->assertSame('&quot;hello&quot;', $this->model->no_html('"hello"'));
        $this->assertSame('&#39;hello&#39;', $this->model->no_html("'hello'"));
    }

    public function testNoHtmlTrimsWhitespace(): void
    {
        $this->assertSame('hello', $this->model->no_html('  hello  '));
    }

    public function testNoHtmlHandlesNull(): void
    {
        $this->assertSame('', $this->model->no_html(null));
    }

    public function testNoHtmlHandlesEmptyString(): void
    {
        $this->assertSame('', $this->model->no_html(''));
    }

    public function testNoHtmlMixedContent(): void
    {
        $input = '<a href="test">O\'Brien</a>';
        $expected = '&lt;a href=&quot;test&quot;&gt;O&#39;Brien&lt;/a&gt;';
        $this->assertSame($expected, $this->model->no_html($input));
    }

    // =====================================================================
    // str2bool()
    // =====================================================================

    public function testStr2boolTrueValues(): void
    {
        $this->assertTrue($this->model->str2bool('t'));    // PostgreSQL TRUE
        $this->assertTrue($this->model->str2bool('1'));    // MySQL TRUE
        $this->assertTrue($this->model->str2bool(1));      // Integer 1
    }

    public function testStr2boolFalseValues(): void
    {
        $this->assertFalse($this->model->str2bool('f'));
        $this->assertFalse($this->model->str2bool('0'));
        $this->assertFalse($this->model->str2bool(0));
        $this->assertFalse($this->model->str2bool(''));
        $this->assertFalse($this->model->str2bool(null));
        $this->assertFalse($this->model->str2bool('true'));
        $this->assertFalse($this->model->str2bool('yes'));
    }

    // =====================================================================
    // floatcmp()
    // =====================================================================

    public function testFloatcmpEqualValues(): void
    {
        $this->assertTrue($this->model->floatcmp(1.0, 1.0));
        $this->assertTrue($this->model->floatcmp(0.0, 0.0));
        $this->assertTrue($this->model->floatcmp(3.14159, 3.14159));
    }

    public function testFloatcmpDifferentValues(): void
    {
        $this->assertFalse($this->model->floatcmp(1.0, 2.0));
        $this->assertFalse($this->model->floatcmp(0.1, 0.2));
    }

    public function testFloatcmpWithPrecision(): void
    {
        // Con precisión 2, 1.001 y 1.002 deberían ser diferentes
        $this->assertFalse($this->model->floatcmp(1.001, 1.002, 3));

        // Con precisión 2, deberían ser iguales
        $this->assertTrue($this->model->floatcmp(1.001, 1.001, 2));
    }

    public function testFloatcmpWithRound(): void
    {
        $this->assertTrue($this->model->floatcmp(1.0, 1.0, 10, true));
        $this->assertFalse($this->model->floatcmp(1.0, 2.0, 10, true));
    }

    // =====================================================================
    // intval()
    // =====================================================================

    public function testIntvalReturnsNullForNull(): void
    {
        $this->assertNull($this->model->intval(null));
    }

    public function testIntvalConvertsString(): void
    {
        $this->assertSame(42, $this->model->intval('42'));
        $this->assertSame(0, $this->model->intval('0'));
        $this->assertSame(0, $this->model->intval('abc'));
    }

    public function testIntvalConvertsFloat(): void
    {
        $this->assertSame(3, $this->model->intval(3.7));
    }

    public function testIntvalPassesThrough(): void
    {
        $this->assertSame(100, $this->model->intval(100));
    }
}
