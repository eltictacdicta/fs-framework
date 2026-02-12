<?php
/**
 * Tests para fs_query_builder — generación de SQL.
 *
 * Se usa un mock de fs_db2 para evitar conexión a DB real.
 * El mock simplemente devuelve el string escapado básico.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsQueryBuilderTest extends TestCase
{
    private \fs_query_builder $qb;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_query_builder.php';

        // Crear mock de fs_db2 que solo implementa escape_string
        $mockDb = new class {
            public function escape_string(string $str): string
            {
                return addslashes($str);
            }
        };

        $this->qb = new \fs_query_builder($mockDb);
    }

    // =====================================================================
    // SELECT básico
    // =====================================================================

    public function testBasicSelect(): void
    {
        $sql = $this->qb->table('clientes')->toSql();
        $this->assertSame('SELECT * FROM clientes', $sql);
    }

    public function testSelectSpecificColumns(): void
    {
        $sql = $this->qb->table('clientes')->select('nombre', 'email')->toSql();
        $this->assertSame('SELECT nombre, email FROM clientes', $sql);
    }

    public function testSelectWithArray(): void
    {
        $sql = $this->qb->table('clientes')->select(['id', 'nombre'])->toSql();
        $this->assertSame('SELECT id, nombre FROM clientes', $sql);
    }

    public function testFromAlias(): void
    {
        $sql = $this->qb->from('pedidos')->toSql();
        $this->assertSame('SELECT * FROM pedidos', $sql);
    }

    public function testDistinct(): void
    {
        $sql = $this->qb->table('clientes')->distinct('nombre')->toSql();
        $this->assertSame('SELECT DISTINCT nombre FROM clientes', $sql);
    }

    // =====================================================================
    // WHERE
    // =====================================================================

    public function testWhereEquals(): void
    {
        $sql = $this->qb->table('clientes')->where('activo', '=', 1)->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE activo = 1', $sql);
    }

    public function testWhereShorthand(): void
    {
        // Cuando se pasan 2 args y el segundo no es un operador, se asume =
        $sql = $this->qb->table('clientes')->where('nombre', 'Juan')->toSql();
        $this->assertSame("SELECT * FROM clientes WHERE nombre = 'Juan'", $sql);
    }

    public function testWhereNull(): void
    {
        $sql = $this->qb->table('clientes')->whereNull('email')->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE email IS NULL', $sql);
    }

    public function testWhereNotNull(): void
    {
        $sql = $this->qb->table('clientes')->whereNotNull('email')->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE email IS NOT NULL', $sql);
    }

    public function testWhereIn(): void
    {
        $sql = $this->qb->table('clientes')->whereIn('id', [1, 2, 3])->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE id IN (1, 2, 3)', $sql);
    }

    public function testWhereNotIn(): void
    {
        $sql = $this->qb->table('clientes')->whereNotIn('id', [4, 5])->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE id NOT IN (4, 5)', $sql);
    }

    public function testWhereBetween(): void
    {
        $sql = $this->qb->table('pedidos')->whereBetween('total', 100, 500)->toSql();
        $this->assertSame('SELECT * FROM pedidos WHERE total BETWEEN 100 AND 500', $sql);
    }

    public function testWhereLike(): void
    {
        $sql = $this->qb->table('clientes')->whereLike('nombre', '%Juan%')->toSql();
        $this->assertSame("SELECT * FROM clientes WHERE nombre LIKE '%Juan%'", $sql);
    }

    public function testOrWhere(): void
    {
        $sql = $this->qb->table('clientes')
            ->where('activo', '=', 1)
            ->orWhere('vip', '=', 1)
            ->toSql();
        $this->assertSame('SELECT * FROM clientes WHERE activo = 1 OR vip = 1', $sql);
    }

    public function testWhereRaw(): void
    {
        $sql = $this->qb->table('clientes')
            ->whereRaw("fecha > NOW() - INTERVAL 30 DAY")
            ->toSql();
        $this->assertSame("SELECT * FROM clientes WHERE fecha > NOW() - INTERVAL 30 DAY", $sql);
    }

    public function testMultipleWhereConditions(): void
    {
        $sql = $this->qb->table('clientes')
            ->where('activo', '=', 1)
            ->where('provincia', 'Madrid')
            ->toSql();
        $this->assertSame("SELECT * FROM clientes WHERE activo = 1 AND provincia = 'Madrid'", $sql);
    }

    // =====================================================================
    // JOIN
    // =====================================================================

    public function testInnerJoin(): void
    {
        $sql = $this->qb->table('pedidos')
            ->join('clientes', 'pedidos.codcliente', '=', 'clientes.codcliente')
            ->toSql();
        $this->assertSame(
            'SELECT * FROM pedidos INNER JOIN clientes ON pedidos.codcliente = clientes.codcliente',
            $sql
        );
    }

    public function testLeftJoin(): void
    {
        $sql = $this->qb->table('clientes')
            ->leftJoin('pedidos', 'clientes.codcliente', '=', 'pedidos.codcliente')
            ->toSql();
        $this->assertSame(
            'SELECT * FROM clientes LEFT JOIN pedidos ON clientes.codcliente = pedidos.codcliente',
            $sql
        );
    }

    // =====================================================================
    // ORDER BY, LIMIT, OFFSET
    // =====================================================================

    public function testOrderBy(): void
    {
        $sql = $this->qb->table('clientes')->orderBy('nombre')->toSql();
        $this->assertSame('SELECT * FROM clientes ORDER BY nombre ASC', $sql);
    }

    public function testOrderByDesc(): void
    {
        $sql = $this->qb->table('pedidos')->orderByDesc('fecha')->toSql();
        $this->assertSame('SELECT * FROM pedidos ORDER BY fecha DESC', $sql);
    }

    public function testLatest(): void
    {
        $sql = $this->qb->table('pedidos')->latest()->toSql();
        $this->assertSame('SELECT * FROM pedidos ORDER BY fecha DESC', $sql);
    }

    // =====================================================================
    // GROUP BY
    // =====================================================================

    public function testGroupBy(): void
    {
        $sql = $this->qb->table('pedidos')
            ->select('codcliente', 'COUNT(*) as total')
            ->groupBy('codcliente')
            ->toSql();
        $this->assertSame(
            'SELECT codcliente, COUNT(*) as total FROM pedidos GROUP BY codcliente',
            $sql
        );
    }

    // =====================================================================
    // INSERT
    // =====================================================================

    public function testInsert(): void
    {
        $sql = $this->qb->table('clientes')
            ->insert(['nombre' => 'Test', 'activo' => 1])
            ->toSql();
        $this->assertSame("INSERT INTO clientes (nombre, activo) VALUES ('Test', 1)", $sql);
    }

    public function testInsertWithNull(): void
    {
        $sql = $this->qb->table('clientes')
            ->insert(['nombre' => 'Test', 'email' => null])
            ->toSql();
        $this->assertSame("INSERT INTO clientes (nombre, email) VALUES ('Test', NULL)", $sql);
    }

    // =====================================================================
    // UPDATE
    // =====================================================================

    public function testUpdate(): void
    {
        $sql = $this->qb->table('clientes')
            ->where('id', '=', 1)
            ->update(['nombre' => 'Nuevo'])
            ->toSql();
        $this->assertSame("UPDATE clientes SET nombre = 'Nuevo' WHERE id = 1", $sql);
    }

    // =====================================================================
    // DELETE
    // =====================================================================

    public function testDelete(): void
    {
        $sql = $this->qb->table('clientes')
            ->where('activo', '=', 0)
            ->delete()
            ->toSql();
        $this->assertSame('DELETE FROM clientes WHERE activo = 0', $sql);
    }

    // =====================================================================
    // Reset
    // =====================================================================

    public function testResetClearsState(): void
    {
        $this->qb->table('clientes')->where('id', '=', 1);
        $this->qb->reset();
        $sql = $this->qb->table('pedidos')->toSql();

        $this->assertSame('SELECT * FROM pedidos', $sql);
    }

    // =====================================================================
    // __toString()
    // =====================================================================

    public function testToString(): void
    {
        $this->qb->table('test');
        $this->assertSame('SELECT * FROM test', (string) $this->qb);
    }

    // =====================================================================
    // Boolean values
    // =====================================================================

    public function testBooleanValues(): void
    {
        $sql = $this->qb->table('clientes')
            ->insert(['activo' => true, 'borrado' => false])
            ->toSql();
        $this->assertSame("INSERT INTO clientes (activo, borrado) VALUES (1, 0)", $sql);
    }
}
