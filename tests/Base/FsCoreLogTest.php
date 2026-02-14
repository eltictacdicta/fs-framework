<?php
/**
 * Tests para fs_core_log — sistema de logging unificado.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsCoreLogTest extends TestCase
{
    private const SQL_TEST_QUERY = 'SELECT 1';

    private \fs_core_log $log;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';

        // Reset estático: usar clear() y crear nueva instancia
        // Forzar reinicio del estado estático via Reflection
        $ref = new \ReflectionClass('fs_core_log');
        $prop = $ref->getProperty('data_log');
        $prop->setAccessible(true);
        $prop->setValue(null, null); // Reset para forzar re-init

        $this->log = new \fs_core_log('test_controller');
    }

    // =====================================================================
    // Mensajes
    // =====================================================================

    public function testNewMessageAddsMessage(): void
    {
        $this->log->new_message('Operación completada');
        $messages = $this->log->get_messages();

        $this->assertCount(1, $messages);
        $this->assertSame('Operación completada', $messages[0]);
    }

    public function testMultipleMessages(): void
    {
        $this->log->new_message('Primero');
        $this->log->new_message('Segundo');

        $this->assertCount(2, $this->log->get_messages());
    }

    public function testCleanMessages(): void
    {
        $this->log->new_message('Temporal');
        $this->log->clean_messages();

        $this->assertEmpty($this->log->get_messages());
    }

    // =====================================================================
    // Errores
    // =====================================================================

    public function testNewErrorAddsError(): void
    {
        $this->log->new_error('Algo falló');
        $errors = $this->log->get_errors();

        $this->assertCount(1, $errors);
        $this->assertSame('Algo falló', $errors[0]);
    }

    public function testCleanErrors(): void
    {
        $this->log->new_error('Error temporal');
        $this->log->clean_errors();

        $this->assertEmpty($this->log->get_errors());
    }

    // =====================================================================
    // Consejos
    // =====================================================================

    public function testNewAdviceAddsAdvice(): void
    {
        $this->log->new_advice('Un consejo');
        $advices = $this->log->get_advices();

        $this->assertCount(1, $advices);
        $this->assertSame('Un consejo', $advices[0]);
    }

    public function testCleanAdvices(): void
    {
        $this->log->new_advice('Temporal');
        $this->log->clean_advices();

        $this->assertEmpty($this->log->get_advices());
    }

    // =====================================================================
    // SQL History
    // =====================================================================

    public function testNewSqlAddsSqlToHistory(): void
    {
        $this->log->new_sql('SELECT * FROM users');
        $history = $this->log->get_sql_history();

        $this->assertCount(1, $history);
        $this->assertSame('SELECT * FROM users', $history[0]);
    }

    public function testCleanSqlHistory(): void
    {
        $this->log->new_sql(self::SQL_TEST_QUERY);
        $this->log->clean_sql_history();

        $this->assertEmpty($this->log->get_sql_history());
    }

    // =====================================================================
    // Controller name y user nick
    // =====================================================================

    public function testControllerName(): void
    {
        $this->assertSame('test_controller', $this->log->controller_name());
    }

    public function testUserNick(): void
    {
        $this->log->set_user_nick('admin');
        $this->assertSame('admin', $this->log->user_nick());
    }

    // =====================================================================
    // Clear all y stats
    // =====================================================================

    public function testClearRemovesEverything(): void
    {
        $this->log->new_message('msg');
        $this->log->new_error('err');
        $this->log->new_advice('adv');
        $this->log->new_sql(self::SQL_TEST_QUERY);

        $this->log->clear();

        $this->assertEmpty($this->log->get_messages());
        $this->assertEmpty($this->log->get_errors());
        $this->assertEmpty($this->log->get_advices());
        $this->assertEmpty($this->log->get_sql_history());
    }

    public function testGetStats(): void
    {
        $this->log->new_message('msg');
        $this->log->new_error('err');
        $this->log->new_advice('adv');
        $this->log->new_sql(self::SQL_TEST_QUERY);

        $stats = $this->log->getStats();

        $this->assertSame(1, $stats['messages']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(1, $stats['advices']);
        $this->assertSame(1, $stats['sql_queries']);
        $this->assertSame(4, $stats['total']);
    }

    // =====================================================================
    // Independencia entre canales
    // =====================================================================

    public function testChannelsAreIndependent(): void
    {
        $this->log->new_message('msg');
        $this->log->new_error('err');

        $this->log->clean_messages();

        $this->assertEmpty($this->log->get_messages());
        $this->assertCount(1, $this->log->get_errors());
    }

    // =====================================================================
    // toJson / toArray
    // =====================================================================

    public function testToArrayReturnsAllEntries(): void
    {
        $this->log->new_message('test');
        $arr = $this->log->toArray();

        $this->assertIsArray($arr);
        $this->assertNotEmpty($arr);
        $this->assertSame('test', $arr[0]['message']);
        $this->assertSame('messages', $arr[0]['channel']);
    }

    public function testToJsonReturnsValidJson(): void
    {
        $this->log->new_message('test');
        $json = $this->log->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertNotEmpty($decoded);
    }
}
