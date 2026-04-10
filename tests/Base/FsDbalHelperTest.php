<?php
/**
 * Tests para la capa de compatibilidad inicial de Doctrine DBAL.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsDbalHelperTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_dbal.php';
        require_once FS_FOLDER . '/base/fs_dbal_engine.php';
        require_once FS_FOLDER . '/base/fs_db2.php';
    }

    public function testRequestedBackendDefaultsToLegacy(): void
    {
        $this->assertSame('legacy', \fs_dbal::requested_backend());
        $this->assertSame('legacy', \fs_dbal::effective_backend());
        $this->assertFalse(\fs_dbal::is_requested());
    }

    public function testDriverNameUsesMysqlByDefault(): void
    {
        $this->assertSame('pdo_mysql', \fs_dbal::driver_name());
        $this->assertSame('pdo_pgsql', \fs_dbal::driver_name('postgresql'));
        $this->assertSame('pdo_pgsql', \fs_dbal::driver_name('pgsql'));
    }

    public function testConnectionParamsMirrorLegacyConstants(): void
    {
        $params = \fs_dbal::connection_params();

        $this->assertSame('pdo_mysql', $params['driver']);
        $this->assertSame(FS_DB_HOST, $params['host']);
        $this->assertSame((int) FS_DB_PORT, $params['port']);
        $this->assertSame(FS_DB_NAME, $params['dbname']);
        $this->assertSame(FS_DB_USER, $params['user']);
        $this->assertSame(FS_DB_PASS, $params['password']);
        $this->assertSame('utf8mb4', $params['charset']);
    }

    public function testConnectionParamsUsePostgresqlCharsetInIsolatedProcess(): void
    {
        $code = <<<PHP
define('FS_DB_TYPE', 'PGSQL');
define('FS_DB_HOST', 'localhost');
define('FS_DB_PORT', '5432');
define('FS_DB_NAME', 'fsframework_test');
define('FS_DB_USER', 'root');
define('FS_DB_PASS', '');
    require_once %s;
echo json_encode(fs_dbal::connection_params());
PHP;
        $code = sprintf($code, var_export(FS_FOLDER . '/base/fs_dbal.php', true));

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
        $params = json_decode((string) $output, true);

        $this->assertNotNull($params);
        $this->assertSame('pdo_pgsql', $params['driver']);
        $this->assertSame(5432, $params['port']);
        $this->assertSame('UTF8', $params['charset']);
    }

    public function testFsDb2ReportsLegacyBackendByDefault(): void
    {
        $db = new \fs_db2();

        $this->assertSame('legacy', $db->get_requested_backend_name());
        $this->assertSame('legacy', $db->get_active_backend_name());
        $this->assertFalse($db->dbal_requested());
        $this->assertFalse($db->using_dbal());
    }

    public function testDbalEngineUsesInjectedConnectionForQueries(): void
    {
        $legacy = new class() extends \fs_db_engine {
            public int $execCalls = 0;
            public int $selectCalls = 0;

            public function begin_transaction() { return true; }
            public function check_table_aux($table_name) { return true; }
            public function close() { return true; }
            public function commit() { return true; }
            public function compare_columns($table_name, $xml_cols, $db_cols) { return ''; }
            public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE) { return ''; }
            public function connect() { return true; }
            public function connected() { return true; }
            public function date_style() { return 'Y-m-d'; }
            public function escape_string($str) { return addslashes($str); }
            public function exec($sql, $transaction = TRUE, $params = []) { $this->execCalls++; return true; }
            public function generate_table($table_name, $xml_cols, $xml_cons) { return 'CREATE TABLE demo'; }
            public function get_columns($table_name) { return [['name' => 'id']]; }
            public function get_constraints($table_name) { return []; }
            public function get_constraints_extended($table_name) { return []; }
            public function get_indexes($table_name) { return []; }
            public function get_locks() { return []; }
            public function lastval() { return 0; }
            public function list_tables() { return [['name' => 'demo']]; }
            public function rollback() { return true; }
            public function select($sql, $params = []) { $this->selectCalls++; return []; }
            public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = []) { return []; }
            public function sql_to_int($col_name) { return 'CAST(' . $col_name . ' AS SIGNED)'; }
            public function get_error_msg() { return ''; }
            public function version() { return 'legacy'; }
        };

        $connection = new class() {
            public array $executed = [];
            public array $queries = [];

            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}

            public function executeStatement($sql, $params = []): int
            {
                $this->executed[] = [$sql, $params];
                return 1;
            }

            public function fetchAllAssociative($sql, $params = []): array
            {
                $this->queries[] = [$sql, $params];
                return [['id' => 1, 'nick' => 'admin']];
            }

            public function fetchOne($sql): string
            {
                return '42';
            }
        };

        $engine = new \fs_dbal_engine($legacy, $connection);

        $this->assertTrue($engine->connected());
        $this->assertSame([['id' => 1, 'nick' => 'admin']], $engine->select('SELECT * FROM fs_users WHERE nick = ?', ['admin']));
        $this->assertTrue($engine->exec('UPDATE fs_users SET admin = ? WHERE nick = ?', false, [true, 'admin']));
        $this->assertSame('42', $engine->lastval());
        $this->assertSame(0, $legacy->selectCalls);
        $this->assertSame(0, $legacy->execCalls);
    }

    public function testDbalEngineFallsBackToLegacyForSchemaAndMultiStatementSql(): void
    {
        $legacy = new class() extends \fs_db_engine {
            public int $execCalls = 0;

            public function begin_transaction() { return true; }
            public function check_table_aux($table_name) { return true; }
            public function close() { return true; }
            public function commit() { return true; }
            public function compare_columns($table_name, $xml_cols, $db_cols) { return ''; }
            public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE) { return ''; }
            public function connect() { return true; }
            public function connected() { return true; }
            public function date_style() { return 'Y-m-d'; }
            public function escape_string($str) { return addslashes($str); }
            public function exec($sql, $transaction = TRUE, $params = []) { $this->execCalls++; return true; }
            public function generate_table($table_name, $xml_cols, $xml_cons) { return 'CREATE TABLE demo'; }
            public function get_columns($table_name) { return [['name' => 'id']]; }
            public function get_constraints($table_name) { return []; }
            public function get_constraints_extended($table_name) { return []; }
            public function get_indexes($table_name) { return []; }
            public function get_locks() { return []; }
            public function lastval() { return 0; }
            public function list_tables() { return [['name' => 'demo']]; }
            public function rollback() { return true; }
            public function select($sql, $params = []) { return []; }
            public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = []) { return []; }
            public function sql_to_int($col_name) { return 'CAST(' . $col_name . ' AS SIGNED)'; }
            public function get_error_msg() { return ''; }
            public function version() { return 'legacy'; }
        };

        $connection = new class() {
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function executeStatement($sql, $params = []): int { return 1; }
            public function fetchAllAssociative($sql, $params = []): array { return []; }
            public function fetchOne($sql): string { return '42'; }
        };

        $engine = new \fs_dbal_engine($legacy, $connection);

        $this->assertSame([['name' => 'id']], $engine->get_columns('demo'));
        $this->assertSame([['name' => 'demo']], $engine->list_tables());
        $this->assertTrue($engine->exec('SET a = 1; SET b = 2;', false));
        $this->assertSame(1, $legacy->execCalls);
    }

    public function testDbalEngineSanitizesLimitAndOffset(): void
    {
        $legacy = new class() extends \fs_db_engine {
            public function begin_transaction() { return true; }
            public function check_table_aux($table_name) { return true; }
            public function close() { return true; }
            public function commit() { return true; }
            public function compare_columns($table_name, $xml_cols, $db_cols) { return ''; }
            public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE) { return ''; }
            public function connect() { return true; }
            public function connected() { return true; }
            public function date_style() { return 'Y-m-d'; }
            public function escape_string($str) { return addslashes($str); }
            public function exec($sql, $transaction = TRUE, $params = []) { return true; }
            public function generate_table($table_name, $xml_cols, $xml_cons) { return 'CREATE TABLE demo'; }
            public function get_columns($table_name) { return []; }
            public function get_constraints($table_name) { return []; }
            public function get_constraints_extended($table_name) { return []; }
            public function get_indexes($table_name) { return []; }
            public function get_locks() { return []; }
            public function lastval() { return 0; }
            public function list_tables() { return []; }
            public function rollback() { return true; }
            public function select($sql, $params = []) { return []; }
            public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = []) { return []; }
            public function sql_to_int($col_name) { return $col_name; }
            public function get_error_msg() { return ''; }
            public function version() { return 'legacy'; }
        };

        $connection = new class() {
            public array $queries = [];

            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function executeStatement($sql, $params = []): int { return 1; }
            public function fetchOne($sql): string { return '1'; }
            public function fetchAllAssociative($sql, $params = []): array
            {
                $this->queries[] = [$sql, $params];
                return [];
            }
        };

        $engine = new \fs_dbal_engine($legacy, $connection);
        $engine->select_limit('SELECT * FROM fs_users', '25; DROP TABLE fs_users', '-10');
        $engine->select_limit('SELECT * FROM fs_users', 999999, 5);

        $this->assertSame('SELECT * FROM fs_users LIMIT 25 OFFSET 0;', $connection->queries[0][0]);
        $this->assertSame('SELECT * FROM fs_users LIMIT 10000 OFFSET 5;', $connection->queries[1][0]);
    }

    public function testDbalEngineSkipsExecWhenTransactionCannotStart(): void
    {
        $legacy = new class() extends \fs_db_engine {
            public function begin_transaction() { return true; }
            public function check_table_aux($table_name) { return true; }
            public function close() { return true; }
            public function commit() { return true; }
            public function compare_columns($table_name, $xml_cols, $db_cols) { return ''; }
            public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE) { return ''; }
            public function connect() { return true; }
            public function connected() { return true; }
            public function date_style() { return 'Y-m-d'; }
            public function escape_string($str) { return addslashes($str); }
            public function exec($sql, $transaction = TRUE, $params = []) { return true; }
            public function generate_table($table_name, $xml_cols, $xml_cons) { return 'CREATE TABLE demo'; }
            public function get_columns($table_name) { return []; }
            public function get_constraints($table_name) { return []; }
            public function get_constraints_extended($table_name) { return []; }
            public function get_indexes($table_name) { return []; }
            public function get_locks() { return []; }
            public function lastval() { return 0; }
            public function list_tables() { return []; }
            public function rollback() { return true; }
            public function select($sql, $params = []) { return []; }
            public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = []) { return []; }
            public function sql_to_int($col_name) { return $col_name; }
            public function get_error_msg() { return ''; }
            public function version() { return 'legacy'; }
        };

        $connection = new class() {
            public int $beginCalls = 0;
            public int $execCalls = 0;
            public int $commitCalls = 0;
            public int $rollbackCalls = 0;

            public function beginTransaction(): void
            {
                $this->beginCalls++;
                throw new \RuntimeException('begin failed');
            }

            public function commit(): void
            {
                $this->commitCalls++;
            }

            public function rollBack(): void
            {
                $this->rollbackCalls++;
            }

            public function executeStatement($sql, $params = []): int
            {
                $this->execCalls++;
                return 1;
            }

            public function fetchAllAssociative($sql, $params = []): array
            {
                return [];
            }

            public function fetchOne($sql): string
            {
                return '1';
            }
        };

        $engine = new \fs_dbal_engine($legacy, $connection);

        $this->assertFalse($engine->exec('UPDATE fs_users SET admin = ?', TRUE, [1]));
        $this->assertSame(1, $connection->beginCalls);
        $this->assertSame(0, $connection->execCalls);
        $this->assertSame(0, $connection->commitCalls);
        $this->assertSame(0, $connection->rollbackCalls);
        $this->assertSame('No se pudo iniciar la transacción DBAL: begin failed', $engine->get_error_msg());
    }
}
