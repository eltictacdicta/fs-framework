<?php

declare(strict_types=1);

/**
 * Tests for the fs_schema constraint sync path.
 *
 * During a plugin install, PluginSchemaSynchronizer runs fs_schema::syncTable()
 * before any model exists, so fs_model::pre_migrate_data() never normalises the
 * '' values that make a FOREIGN KEY ADD fail with MySQL errno 1452. These tests
 * prove the schema path normalises first (reusing fs_model's guarded logic) and
 * that a failed schema statement is reported instead of swallowed.
 */

namespace Tests\Core;

use FSFramework\Database\FkDataSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_schema.php';

#[CoversClass(\fs_schema::class)]
#[CoversClass(FkDataSanitizer::class)]
final class FsSchemaConstraintSanitizationTest extends TestCase
{
    private const TABLE = 'oidc_clients';

    private const FK_SQL = 'ALTER TABLE `oidc_clients` ADD CONSTRAINT ca_oidc_clients_clientes '
        . 'FOREIGN KEY (codcliente) REFERENCES clientes (codcliente)'
        . ' ON DELETE CASCADE ON UPDATE CASCADE;';

    private string $tempDir;

    private ?string $logFile = null;

    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        $this->injectDb(null);

        $this->tempDir = sys_get_temp_dir() . '/fs_schema_sanitize_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $this->previousErrorLog = (string) ini_get('error_log');
        $logFile = tempnam(sys_get_temp_dir(), 'fs_schema_err_');
        $this->logFile = $logFile === false ? null : $logFile;
        if ($this->logFile !== null) {
            ini_set('error_log', $this->logFile);
        }
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        if ($this->logFile !== null && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->injectDb(null);
        $this->removeTree($this->tempDir);
    }

    /**
     * The production bug: a nullable FK column full of '' gets the value
     * normalised before the ADD is emitted, and the UPDATE runs first.
     */
    #[Test]
    public function normalisesNullableForeignKeyAndEmitsUpdateBeforeTheConstraint(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'YES']];
        $db->addConstraintSql = self::FK_SQL;

        $changes = [];
        $this->runSyncConstraints($db, $this->fkXml(true), $changes);

        $this->assertCount(2, $db->executed, 'The sanitising UPDATE and the ADD must both run');
        $this->assertStringStartsWith('UPDATE `oidc_clients`', $db->executed[0]);
        $this->assertStringContainsString("SET `codcliente` = NULL WHERE `codcliente` = '';", $db->executed[0]);
        $this->assertStringContainsString('ADD CONSTRAINT ca_oidc_clients_clientes', $db->executed[1]);
        $this->assertContains('Restricciones actualizadas en oidc_clients', $changes);
        $this->assertSame('', $this->errorLogContents(), 'A successful sync must not log an error');
    }

    /**
     * Guard: a NOT NULL FK column has no NULL state to fall back to, so the ''
     * must stay put (do not falsify a real integrity problem).
     */
    #[Test]
    public function doesNotNormaliseWhenTheForeignKeyColumnIsNotNull(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'NO']];
        $db->addConstraintSql = self::FK_SQL;

        $changes = [];
        $this->runSyncConstraints($db, $this->fkXml(false), $changes);

        $this->assertCount(1, $db->executed, 'Only the ADD may run for a NOT NULL column');
        $this->assertStringContainsString('ADD CONSTRAINT', $db->executed[0]);
        $this->assertSame([], $db->selected, 'A NOT NULL column needs no reference lookup');
    }

    /**
     * Guard: when the referenced table actually holds '' as a key, that '' is a
     * real reference and must survive untouched.
     */
    #[Test]
    public function doesNotNormaliseWhenTheReferencedTableHoldsAnEmptyKey(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'YES']];
        $db->addConstraintSql = self::FK_SQL;
        $db->referenceRows = [['matched' => 1]];

        $changes = [];
        $this->runSyncConstraints($db, $this->fkXml(true), $changes);

        $this->assertCount(1, $db->executed, "A '' held as a key must not be nulled");
        $this->assertStringContainsString('ADD CONSTRAINT', $db->executed[0]);
        $this->assertCount(1, $db->selected);
        $this->assertStringContainsString("WHERE `codcliente` = '' LIMIT 1", $db->selected[0]);
    }

    /**
     * A failed ADD used to be swallowed (there was no else). It must now be
     * reported in the flat change list and reach error_log with the DB message.
     */
    #[Test]
    public function reportsAFailedConstraintStatementInsteadOfSwallowingIt(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'YES']];
        $db->addConstraintSql = self::FK_SQL;
        $db->failOnlyConstraintSql = true;

        $changes = [];
        $this->runSyncConstraints($db, $this->fkXml(true), $changes);

        $errors = array_values(
            array_filter($changes, static fn (string $change): bool => str_starts_with($change, '[sync-error]'))
        );
        $this->assertNotEmpty($errors, 'The failed ADD must surface as an error');
        $this->assertStringContainsString('ADD CONSTRAINT ca_oidc_clients_clientes', $errors[0]);
        $this->assertStringContainsString('1452', $errors[0]);
        $this->assertNotContains('Restricciones actualizadas en oidc_clients', $changes);

        $logged = $this->errorLogContents();
        $this->assertNotSame('', $logged, 'The failure must also reach error_log');
        $this->assertStringContainsString('1452', $logged);
    }

    /**
     * The existing errors channel (syncPluginTables()['errors']) must carry the
     * failure, not the success changes list.
     */
    #[Test]
    public function routesFailedStatementIntoTheErrorsChannelThroughSyncPluginTables(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'YES']];
        $db->addConstraintSql = self::FK_SQL;
        $db->failOnlyConstraintSql = true;
        $this->injectDb($db);
        $this->writeXml(self::TABLE, true);

        $result = \fs_schema::syncPluginTables($this->tempDir);

        $this->assertNotEmpty($result['errors'], 'The error channel must carry the failed statement');
        $this->assertStringContainsString('1452', implode(' ', $result['errors']));
        $this->assertNotContains('Restricciones actualizadas en oidc_clients', $result['changes']);
    }

    /**
     * The whole purpose of the sync must keep working: a genuinely missing
     * constraint is still added.
     */
    #[Test]
    public function stillAddsAGenuinelyMissingConstraint(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'codcliente', 'is_nullable' => 'YES']];
        $db->addConstraintSql = self::FK_SQL;
        $this->injectDb($db);
        $this->writeXml(self::TABLE, true);

        $result = \fs_schema::syncPluginTables($this->tempDir);

        $this->assertSame([], $result['errors']);
        $this->assertContains('Restricciones actualizadas en oidc_clients', $result['changes']);
        $this->assertNotEmpty(
            array_filter($db->executed, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE'))
        );
    }

    /**
     * Parity with the model path: a nullable UNIQUE column is normalised too.
     */
    #[Test]
    public function normalisesNullableUniqueColumnForParityWithTheModelPath(): void
    {
        $db = $this->recordingDb();
        $db->columns = [['name' => 'client_id', 'is_nullable' => 'YES']];
        $db->addConstraintSql = 'ALTER TABLE `oidc_clients` ADD CONSTRAINT oidc_clients_client_id_unique UNIQUE (client_id);';

        $xml = $this->xml(<<<'XML'
<tabla>
    <columna>
        <nombre>client_id</nombre>
        <tipo>character varying(64)</tipo>
        <nulo>YES</nulo>
    </columna>
    <restriccion>
        <nombre>oidc_clients_client_id_unique</nombre>
        <consulta>UNIQUE (client_id)</consulta>
    </restriccion>
</tabla>
XML);

        $changes = [];
        $this->runSyncConstraints($db, $xml, $changes);

        $this->assertStringStartsWith('UPDATE `oidc_clients`', $db->executed[0]);
        $this->assertStringContainsString("SET `client_id` = NULL WHERE `client_id` = '';", $db->executed[0]);
        $this->assertContains('Restricciones actualizadas en oidc_clients', $changes);
    }

    /**
     * @param array<int, string> $changes
     */
    private function runSyncConstraints(RecordingSchemaDb $db, \SimpleXMLElement $xml, array &$changes): void
    {
        $method = new \ReflectionMethod(\fs_schema::class, 'syncConstraints');
        $method->setAccessible(true);

        $args = [$db, self::TABLE, $xml, &$changes];
        $method->invokeArgs(null, $args);
    }

    private function fkXml(bool $nullable): \SimpleXMLElement
    {
        $nulo = $nullable ? 'YES' : 'NO';

        return $this->xml(<<<XML
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>codcliente</nombre>
        <tipo>character varying(6)</tipo>
        <nulo>{$nulo}</nulo>
    </columna>
    <restriccion>
        <nombre>ca_oidc_clients_clientes</nombre>
        <consulta>FOREIGN KEY (codcliente) REFERENCES clientes (codcliente) ON DELETE CASCADE ON UPDATE CASCADE</consulta>
    </restriccion>
</tabla>
XML);
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        $element = simplexml_load_string($xml);
        if ($element === false) {
            throw new \RuntimeException('Invalid XML in test fixture');
        }

        return $element;
    }

    private function writeXml(string $table, bool $nullable): void
    {
        file_put_contents($this->tempDir . '/' . $table . '.xml', $this->fkXml($nullable)->asXML());
    }

    private function recordingDb(): RecordingSchemaDb
    {
        return new RecordingSchemaDb();
    }

    private function injectDb(?object $db): void
    {
        $ref = new \ReflectionClass(\fs_schema::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue(null, $db);
    }

    private function errorLogContents(): string
    {
        if ($this->logFile === null || !file_exists($this->logFile)) {
            return '';
        }

        return (string) file_get_contents($this->logFile);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

/**
 * Minimal fs_db2-compatible adapter that records the SQL it is asked to run.
 */
final class RecordingSchemaDb
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $selected = [];

    /** @var list<array{name: string, is_nullable: string}> */
    public array $columns = [];

    /** @var list<array<string, mixed>> */
    public array $referenceRows = [];

    public string $addConstraintSql = '';

    public bool $failOnlyConstraintSql = false;

    public string $errorMessage = 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row';

    public function get_columns(string $table): array
    {
        return $this->columns;
    }

    public function select(string $sql): array
    {
        $this->selected[] = $sql;

        return $this->referenceRows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false): bool
    {
        $this->executed[] = (string) $sql;

        if ($this->failOnlyConstraintSql && str_starts_with(ltrim((string) $sql), 'ALTER TABLE')) {
            return false;
        }

        return true;
    }

    public function get_constraints(string $table): array
    {
        return [];
    }

    public function compare_constraints($table, $xmlCons, $dbCons, $deleteOnly = false): string
    {
        return $this->addConstraintSql;
    }

    public function compare_columns($table, $xmlCols, $dbCols): string
    {
        return '';
    }

    public function table_exists(string $table, $list = false): bool
    {
        return true;
    }

    public function get_error_msg(): string
    {
        return $this->errorMessage;
    }
}
