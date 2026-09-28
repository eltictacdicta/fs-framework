<?php
/**
 * This file is part of FSFramework
 *
 * Regression coverage for the schema filter used by the core schema inspector.
 *
 * On some production MariaDB servers (observed behind Plesk), filtering an
 * information_schema query with SCHEMA() silently matches zero rows, while
 * DATABASE() resolves the current schema correctly. If the inspector uses
 * SCHEMA(), getConstraintsExtended() returns an empty list, SchemaComparator
 * believes an existing foreign key is missing and emits ADD CONSTRAINT, which
 * the server rejects with errno 1452 on every request.
 *
 * These tests pin the contract, not the implementation: every inspector query
 * must resolve the current schema with DATABASE() and must still filter by the
 * requested table name. A revert to SCHEMA() fails them.
 */

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\SchemaInspector;
use PHPUnit\Framework\TestCase;

final class SchemaInspectorSchemaFilterTest extends TestCase
{
    private const TABLE = 'oidc_clients';

    public function testGetConstraintsFiltersCurrentSchemaWithDatabaseNotSchema(): void
    {
        $db = self::recordingDb();
        (new SchemaInspector($db))->getConstraints(self::TABLE);

        $sql = self::onlyQuery($db);
        $this->assertStringContainsStringIgnoringCase('information_schema', $sql);
        $this->assertStringContainsStringIgnoringCase('DATABASE()', $sql);
        $this->assertStringNotContainsStringIgnoringCase('SCHEMA()', $sql);
        $this->assertMatchesRegularExpression("/table_name\s*=\s*'" . self::TABLE . "'/i", $sql);
    }

    public function testGetConstraintsExtendedFiltersCurrentSchemaWithDatabaseNotSchema(): void
    {
        $db = self::recordingDb();
        (new SchemaInspector($db))->getConstraintsExtended(self::TABLE);

        $sql = self::onlyQuery($db);
        $this->assertStringContainsStringIgnoringCase('information_schema', $sql);
        $this->assertStringContainsStringIgnoringCase('DATABASE()', $sql);
        $this->assertStringNotContainsStringIgnoringCase('SCHEMA()', $sql);
        $this->assertMatchesRegularExpression("/table_name\s*=\s*'" . self::TABLE . "'/i", $sql);
    }

    public function testGetFkColumnNamesFiltersCurrentSchemaWithDatabaseNotSchema(): void
    {
        $db = self::recordingDb();
        (new SchemaInspector($db))->getFkColumnNames(self::TABLE);

        $sql = self::onlyQuery($db);
        $this->assertStringContainsStringIgnoringCase('information_schema', $sql);
        $this->assertStringContainsStringIgnoringCase('DATABASE()', $sql);
        $this->assertStringNotContainsStringIgnoringCase('SCHEMA()', $sql);
        $this->assertMatchesRegularExpression("/table_name\s*=\s*'" . self::TABLE . "'/i", $sql);
    }

    /**
     * Minimal fs_db2-shaped collaborator: SchemaInspector only depends on
     * select(), so recording the SQL is enough to assert the emitted query
     * without opening a real database connection.
     */
    private static function recordingDb(): object
    {
        return new class() {
            /** @var string[] */
            public array $queries = [];

            public function select(string $sql, array $params = []): array
            {
                $this->queries[] = $sql;

                return [];
            }
        };
    }

    private static function onlyQuery(object $db): string
    {
        self::assertCount(1, $db->queries, 'The inspector must issue exactly one schema query.');

        return $db->queries[0];
    }
}
