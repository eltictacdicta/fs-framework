<?php

declare(strict_types=1);

/**
 * Tests for fs_model::pre_migrate_data().
 *
 * The method normalises '' to NULL on columns that are declared UNIQUE in the
 * XML or that participate in a FOREIGN KEY. It only touches nullable columns
 * and never rewrites a '' that the referenced table actually holds as a key,
 * because that '' is a legitimate reference.
 *
 * The real SQL is executed against an isolated in-memory SQLite database so the
 * assertions prove behaviour, not that a query was built. fs_db2's contract is
 * emulated by a small adapter that exposes get_columns() + select() + exec().
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\fs_model::class)]
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
    #[Test]
    public function emptyStringInUniqueColumnBecomesNull(): void
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
     * A nullable FK column with '' becomes NULL. The referenced table is
     * missing here, proving the lookup failure is swallowed and the
     * normalisation still happens.
     */
    #[Test]
    public function emptyStringInForeignKeyColumnBecomesNull(): void
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
     * F1 (forward direction): when the referenced table keeps '' as a key, the
     * '' in the FK column is a real reference and must survive untouched.
     */
    #[Test]
    public function emptyStringForeignKeyIsPreservedWhenTheReferenceHoldsEmptyKey(): void
    {
        $this->createReferenceTable('pre_migrate_ref_empty', ['', 'C001']);

        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES pre_migrate_ref_empty (code)'],
        ]);

        $this->assertSame(
            '',
            $this->row(1)['fk_col'],
            'An empty string held as a key by the referenced table must not be nulled'
        );
    }

    /**
     * F1 (reverse direction): when the referenced table has no '' key, the FK
     * '' is normalised to NULL, so the production fix stays intact.
     */
    #[Test]
    public function emptyStringForeignKeyIsNulledWhenTheReferenceHasNoEmptyKey(): void
    {
        $this->createReferenceTable('pre_migrate_ref_solid', ['C001']);

        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES pre_migrate_ref_solid (code)'],
        ]);

        $this->assertNull(
            $this->row(1)['fk_col'],
            'An empty string with no matching reference key must still become NULL'
        );
    }

    /**
     * Guard: a NOT NULL FK column with no XML evidence of nullability has no
     * NULL state to fall back to, so it must be left alone.
     */
    #[Test]
    public function emptyStringInNotNullForeignKeyColumnIsLeftAlone(): void
    {
        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk_nn', 'consulta' => 'FOREIGN KEY (fk_notnull) REFERENCES other (id)'],
        ]);

        $row = $this->row(1);
        $this->assertSame('', $row['fk_notnull'], 'A NOT NULL FK column must never be set to NULL');
    }

    /**
     * F2: the DB metadata says NOT NULL but the XML declares the column
     * nullable, so compare_columns() will soften it after this method. The ''
     * must be normalised anyway, or it survives and the FK creation fails.
     *
     * The adapter reports the column as NOT NULL while the physical SQLite
     * column stays nullable, isolating the DB+XML decision from the physical
     * NOT NULL constraint (which MySQL would eventually relax via the XML).
     */
    #[Test]
    public function emptyStringIsNormalisedWhenDbSaysNotNullButXmlSaysNullable(): void
    {
        $this->db->nullableOverrides['fk_col'] = 'NO';
        $this->setXmlColumns([
            ['nombre' => 'fk_col', 'nulo' => 'YES'],
        ]);

        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData([
            ['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES pre_migrate_ref_solid (code)'],
        ]);

        $this->assertNull(
            $this->row(1)['fk_col'],
            'The XML nullability must be enough to normalise, even when the DB reports NOT NULL'
        );
    }

    /**
     * F3: a table name that fails identifier validation is skipped defensively
     * and no SQL is emitted (no UPDATE, no metadata lookup).
     */
    #[Test]
    public function invalidTableIdentifierIsSkippedWithoutEmittingSql(): void
    {
        $this->seedRow('', '', '', 'keep');

        $this->runPreMigrateData(
            [['nombre' => 'w_fk', 'consulta' => 'FOREIGN KEY (fk_col) REFERENCES pre_migrate_ref_solid (code)']],
            'bad; DROP TABLE ' . self::TABLE
        );

        $this->assertSame([], $this->db->executedSql, 'No UPDATE may be emitted for an invalid identifier');
        $this->assertSame([], $this->db->selectedSql, 'No lookup may run for an invalid identifier');
        $this->assertSame('', $this->row(1)['fk_col']);
    }

    /**
     * Non-empty values are never rewritten, and unrelated constraints are
     * ignored.
     */
    #[Test]
    public function nonEmptyValuesArePreserved(): void
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
    private function runPreMigrateData(array $constraints, string $table = self::TABLE): void
    {
        $method = new \ReflectionMethod(\fs_model::class, 'pre_migrate_data');
        $method->setAccessible(true);
        $method->invoke($this->model, $table, $constraints);
    }

    /**
     * @param list<array{nombre: string, nulo: string}> $columns
     */
    private function setXmlColumns(array $columns): void
    {
        $property = new \ReflectionProperty(\fs_model::class, 'xml_columns');
        $property->setAccessible(true);
        $property->setValue($this->model, [self::TABLE => $columns]);
    }

    /**
     * @param list<string> $codes
     */
    private function createReferenceTable(string $table, array $codes): void
    {
        $this->pdo->exec('CREATE TABLE ' . $table . ' (code TEXT PRIMARY KEY);');
        $stmt = $this->pdo->prepare('INSERT INTO ' . $table . ' (code) VALUES (?)');
        foreach ($codes as $code) {
            $stmt->execute([$code]);
        }
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
     * Minimal fs_db2-compatible adapter exposing the methods
     * pre_migrate_data() actually calls.
     */
    private function buildDbAdapter(\PDO $pdo): object
    {
        return new class ($pdo) {
            private \PDO $pdo;

            /** @var list<string> */
            public array $executedSql = [];

            /** @var list<string> */
            public array $selectedSql = [];

            /** @var array<string, string> */
            public array $nullableOverrides = [];

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
                    $name = (string) $info['name'];
                    $isNullable = ((int) $info['notnull']) === 1 ? 'NO' : 'YES';
                    if (isset($this->nullableOverrides[$name])) {
                        $isNullable = $this->nullableOverrides[$name];
                    }

                    $columns[] = [
                        'name' => $name,
                        'is_nullable' => $isNullable,
                    ];
                }

                return $columns;
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function select(string $sql): array
            {
                $this->selectedSql[] = $sql;
                $stmt = $this->pdo->query($sql);

                return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            public function exec($sql, $transaction = null, $params = [], $batch = false): bool
            {
                $this->executedSql[] = $sql;

                return $this->pdo->exec($sql) !== false;
            }
        };
    }
}
