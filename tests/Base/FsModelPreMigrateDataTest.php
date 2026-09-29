<?php
/**
 * Tests for fs_model::pre_migrate_data().
 *
 * The method normalises '' to NULL on columns that are declared UNIQUE in the
 * XML. It was extended to also cover columns participating in a FOREIGN KEY,
 * because an empty string in an FK column is never valid and is exactly how
 * the oidc_clients.codcliente data-loss bug was born.
 *
 * The real SQL is executed against an isolated in-memory SQLite database so the
 * assertions prove behaviour, not that a query was built. fs_db2's contract is
 * emulated by a small adapter that exposes get_columns() + exec().
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsModelPreMigrateDataTest extends TestCase
{
    private const TABLE = 'pre_migrate_widgets';

    private \PDO $pdo;
    private object $db;
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . ' id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' uniq_col TEXT NULL,'
            . ' fk_col TEXT NULL,'
            . ' fk_notnull TEXT NOT NULL DEFAULT \'\','
            . ' other_col TEXT NULL'
            . ');'
        );

        $this->db = $this->buildDbAdapter($this->pdo);

        // Concrete subclass with an empty constructor (no DB/cache) and the
        // adapter injected into the protected $db property.
        $this->model = new class() extends \fs_model {
            public function __construct()
            {
                // Skip the DB-bound parent constructor.
            }

            public function delete()
            {
                return false;
            }

            public function exists()
            {
                return false;
            }

            public function save()
            {
                return false;
            }
        };

        $dbProperty = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->model, $this->db);
    }

    /**
     * Pre-existing behaviour: a nullable UNIQUE column with '' becomes NULL.
     */
    public function testEmptyStringInUniqueColumnBecomesNull(): void
    {
        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_uniq', 'consulta' => 'UNIQUE (uniq_col)'],
        ]);

        $row = $this->row(1);
        $this->assertNull($row['uniq_col'], 'An empty UNIQUE column must become NULL');
        $this->assertSame('', $row['fk_col'], 'The UNIQUE branch must not touch unrelated columns');
    }

    /**
     * New behaviour: a nullable FK column with '' becomes NULL.
     */
    public function testEmptyStringInForeignKeyColumnBecomesNull(): void
    {
        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES other (id)'],
        ]);

        $row = $this->row(1);
        $this->assertNull($row['fk_col'], 'An empty FK column must become NULL');
        $this->assertSame('', $row['uniq_col'], 'The FK branch must not touch unrelated columns');
    }

    /**
     * Guard: a NOT NULL FK column has no NULL state to fall back to, so it must
     * be left alone (matching the existing is_nullable === 'YES' guard).
     */
    public function testEmptyStringInNotNullForeignKeyColumnIsLeftAlone(): void
    {
        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk_nn', 'consulta' => 'FOREIGN KEY (fk_notnull) REFERENCES other (id)'],
        ]);

        $row = $this->row(1);
        $this->assertSame('', $row['fk_notnull'], 'A NOT NULL FK column must never be set to NULL');
    }

    /**
     * Non-empty values are never rewritten, and unrelated constraints are
     * ignored.
     */
    public function testNonEmptyValuesArePreserved(): void
    {
        $this->seedRow('U1', 'C001', 'N1', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_uniq', 'consulta' => 'UNIQUE (uniq_col)'],
            ['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES other (id)'],
            ['nombre' => 'w_pk', 'consulta' => 'PRIMARY KEY (id)'],
        ]);

        $row = $this->row(1);
        $this->assertSame('U1', $row['uniq_col']);
        $this->assertSame('C001', $row['fk_col']);
        $this->assertSame('N1', $row['fk_notnull']);
    }

    /**
     * @param list<array{nombre: string, consulta: string}> $constraints
     */
    private function runPreMigrateData(array $constraints): void
    {
        $method = new \ReflectionMethod(\fs_model::class, 'pre_migrate_data');
        $method->setAccessible(true);
        $method->invoke($this->model, self::TABLE, $constraints);
    }

    private function seedRow(string $uniq, ?string $fk, string $fkNotNull, string $other): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (uniq_col, fk_col, fk_notnull, other_col) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$uniq, $fk, $fkNotNull, $other]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Minimal fs_db2-compatible adapter exposing the two methods
     * pre_migrate_data() actually calls.
     */
    private function buildDbAdapter(\PDO $pdo): object
    {
        return new class ($pdo) {
            private \PDO $pdo;

            public function __construct(\PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            /**
             * @return list<array{name: string, is_nullable: string}>
             */
            public function get_columns(string $table): array
            {
                $columns = [];
                foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')') as $info) {
                    $columns[] = [
                        'name' => (string) $info['name'],
                        'is_nullable' => ((int) $info['notnull']) === 1 ? 'NO' : 'YES',
                    ];
                }

                return $columns;
            }

            public function exec($sql, $transaction = null, $params = [], $batch = false): bool
            {
                return $this->pdo->exec($sql) !== false;
            }
        };
    }
}
