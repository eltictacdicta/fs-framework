<?php
declare(strict_types=1);
/**
 * Tests for clientes_facturacion document numbering helpers.
 */

namespace Tests\ClientesFacturacion;

use PHPUnit\Framework\TestCase;

class DocumentCodeFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('FS_NEW_CODIGO')) {
            define('FS_NEW_CODIGO', 'new');
        }
        if (!defined('FS_FACTURA')) {
            define('FS_FACTURA', 'factura');
        }

        require_once FS_FOLDER . '/plugins/clientes_facturacion/functions.php';
    }

    public function testDocumentCodeFunctionsExistInGlobalNamespace(): void
    {
        $this->assertTrue(function_exists('fs_documento_new_numero'));
        $this->assertTrue(function_exists('fs_documento_new_codigo'));
        $this->assertTrue(function_exists('fs_huecos_facturas_cliente'));
    }

    public function testDocumentoNewCodigoDefaultFormat(): void
    {
        $codigo = \fs_documento_new_codigo('presupuesto', '2026', 'A', 12);

        $this->assertSame('PRE2026A12', $codigo);
    }

    public function testDocumentoNewNumeroWorksWithoutSecuenciaModel(): void
    {
        $db = new class {
            public function sql_to_int(string $field): string
            {
                return 'CAST(' . $field . ' AS UNSIGNED)';
            }

            public function var2str(mixed $value): string
            {
                return "'" . addslashes((string) $value) . "'";
            }

            public function select(string $sql): array
            {
                return [['num' => 0]];
            }
        };

        $numero = \fs_documento_new_numero($db, 'presupuestoscli', '2026', 'A', 'npresupuestocli');

        $this->assertSame('1', $numero);
    }
}
