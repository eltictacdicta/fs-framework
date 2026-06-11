<?php
declare(strict_types=1);

/**
 * Tests that fs_list_controller escapes search queries for SQL context
 * using $this->db->escape_string() instead of htmlspecialchars().
 *
 * Spec: critical-security-fixes-2026-03, Requirement C1
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsListControllerSearchTest extends TestCase
{
    private object $controller;
    private object $mockDb;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_list_controller.php';

        // Mock fs_db2 with escape_string that adds backslash before quotes
        $this->mockDb = new class {
            public function escape_string(string $str): string
            {
                return addslashes($str);
            }

            public function table_exists(string $table): bool
            {
                return true;
            }

            public function select(string $sql): array
            {
                return [['num' => 0]];
            }

            public function select_limit(string $sql, int $limit, int $offset): array
            {
                return [];
            }
        };

        // Create a concrete subclass skipping the parent constructor (avoids DB/Kernel)
        $this->controller = new class() extends \fs_list_controller {
            public function __construct()
            {
                // Skip fs_controller constructor — no Kernel/DB init
            }

            protected function create_tabs(): void
            {
            }

            // Expose protected method for testing
            public function callLoadDataFromWhere(string $tabName): string
            {
                return $this->load_data_from_where($tabName);
            }
        };

        // Inject mock DB via Reflection (property is protected in fs_controller)
        $ref = new \ReflectionClass($this->controller);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($this->controller, $this->mockDb);

        // Set up a tab with search columns
        $this->controller->tabs['test_tab'] = [
            'table' => 'test_table',
            'search_columns' => ['nombre', 'email'],
            'count' => 0,
            'cursor' => [],
            'filters' => [],
        ];
        $this->controller->active_tab = 'test_tab';
        $this->controller->query = '';
    }

    // =====================================================================
    // C1-1: Normal search term returns matching rows (escaped correctly)
    // =====================================================================

    public function testNormalSearchIsEscapedAndInterpolated(): void
    {
        $this->controller->query = 'juan';

        $sql = $this->controller->callLoadDataFromWhere('test_tab');

        // escape_string('juan') = 'juan' (no special chars to escape)
        $this->assertStringContainsString("LIKE '%juan%'", $sql);
        $this->assertStringNotContainsString('htmlspecialchars', $sql);
    }

    // =====================================================================
    // C1-2: Search term with single quote is safely escaped
    // =====================================================================

    public function testSingleQuoteIsEscapedByEscapeString(): void
    {
        $this->controller->query = "O'Brien";

        $sql = $this->controller->callLoadDataFromWhere('test_tab');

        // The mock escape_string uses addslashes, then mb_strtolower is applied
        $expected = mb_strtolower(addslashes("O'Brien"), 'UTF8');
        $this->assertStringContainsString($expected, $sql);
        // The raw unescaped value must NOT appear in the LIKE clause
        $this->assertStringNotContainsString("o'brien", $sql);
    }

    // =====================================================================
    // C1-3: Search term with HTML entities is handled correctly
    // =====================================================================

    public function testHtmlCharsArePassedThroughRaw(): void
    {
        $this->controller->query = '<script>';

        $sql = $this->controller->callLoadDataFromWhere('test_tab');

        // escape_string('<script>') = '<script>' (no SQL-special chars)
        // HTML chars should NOT be entity-encoded (that was the old htmlspecialchars bug)
        $this->assertStringContainsString('<script>', $sql);
        $this->assertStringNotContainsString('&lt;script&gt;', $sql);
    }

    // =====================================================================
    // Additional: SQL injection attempt is neutralized
    // =====================================================================

    public function testSqlInjectionAttemptIsEscaped(): void
    {
        $this->controller->query = "' OR 1=1--";

        $sql = $this->controller->callLoadDataFromWhere('test_tab');

        // The mock escape_string uses addslashes: "'" → "\\'"
        // After mb_strtolower, the full escaped query appears in the LIKE clause
        $expected = mb_strtolower(addslashes("' OR 1=1--"), 'UTF8');
        $this->assertStringContainsString($expected, $sql);
        // Verify the quote was escaped: the escaped form differs from raw lowercased input
        $rawLowered = mb_strtolower("' OR 1=1--", 'UTF8');
        $this->assertNotSame($rawLowered, $expected, 'escape_string must alter the raw input');
    }

    // =====================================================================
    // Additional: Empty query produces no LIKE clause
    // =====================================================================

    public function testEmptyQueryProducesNoLikeClause(): void
    {
        $this->controller->query = '';

        $sql = $this->controller->callLoadDataFromWhere('test_tab');

        $this->assertStringNotContainsString('LIKE', $sql);
    }
}
