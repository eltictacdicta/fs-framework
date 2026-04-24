<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'base/fs_db_engine.php';
require_once 'base/fs_mysql.php';
require_once 'base/fs_postgresql.php';
require_once 'base/fs_dbal.php';

/**
 * Adaptador inicial para usar Doctrine DBAL debajo de fs_db2.
 *
 * Este motor mantiene la compatibilidad máxima:
 * - usa DBAL para consultas, transacciones y ejecución simple;
 * - delega al motor legacy para introspección y sincronización XML;
 * - hace fallback automático a legacy en SQL multi-statement.
 */
class fs_dbal_engine extends fs_db_engine
{
    private const MAX_SELECT_LIMIT = 10000;

    /**
     * Conexión DBAL activa.
     *
     * @var object|null
     */
    private static $connection;

    /**
     * Último error ocurrido.
     *
     * @var string
     */
    private static $last_error = '';

    /**
     * Nº de filas afectadas por la última sentencia de escritura.
     *
     * @var integer
     */
    private static $last_affected_rows = 0;

    /**
     * Motor legacy de apoyo para operaciones de esquema y fallback.
     *
     * @var fs_mysql|fs_postgresql|fs_db_engine
     */
    private $legacyEngine;

    /**
     * @param fs_db_engine|null $legacyEngine
     * @param object|null $connection
     */
    public function __construct($legacyEngine = null, $connection = null)
    {
        parent::__construct();
        $this->legacyEngine = $legacyEngine ?: $this->create_legacy_engine();

        if ($connection !== null) {
            self::$connection = $connection;
        }
    }

    public function begin_transaction()
    {
        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        try {
            $connection->beginTransaction();
            return TRUE;
        } catch (\Throwable $e) {
            return $this->log_error_message('No se pudo iniciar la transacción DBAL: ' . $e->getMessage());
        }
    }

    public function affected_rows()
    {
        return self::$last_affected_rows;
    }

    public function check_table_aux($table_name)
    {
        return $this->legacyEngine->check_table_aux($table_name);
    }

    public function close()
    {
        try {
            if (self::$connection !== null && method_exists(self::$connection, 'close')) {
                self::$connection->close();
            }
        } catch (\Throwable $e) {
            $this->log_exception($e);
        }

        self::$connection = null;
        self::$last_error = '';

        if ($this->legacyEngine->connected()) {
            $this->legacyEngine->close();
        }

        return TRUE;
    }

    public function commit()
    {
        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        try {
            self::$t_transactions++;
            $connection->commit();
            return TRUE;
        } catch (\Throwable $e) {
            return $this->log_exception($e);
        }
    }

    public function compare_columns($table_name, $xml_cols, $db_cols)
    {
        return $this->legacyEngine->compare_columns($table_name, $xml_cols, $db_cols);
    }

    public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE)
    {
        return $this->legacyEngine->compare_constraints($table_name, $xml_cons, $db_cons, $delete_only);
    }

    public function connect()
    {
        if (self::$connection !== null) {
            return TRUE;
        }

        if (!fs_dbal::is_available()) {
            self::$last_error = fs_dbal::unavailable_reason();
            self::$core_log->new_error(self::$last_error);
            self::$core_log->save(self::$last_error);
            return FALSE;
        }

        try {
            self::$connection = \Doctrine\DBAL\DriverManager::getConnection(fs_dbal::connection_params());
            return TRUE;
        } catch (\Throwable $e) {
            return $this->log_exception($e);
        }
    }

    public function connected()
    {
        return self::$connection !== null;
    }

    public function date_style()
    {
        return $this->legacyEngine->date_style();
    }

    public function escape_string($str)
    {
        return $this->legacyEngine->escape_string($str);
    }

    public function exec($sql, $transaction = TRUE, $params = [])
    {
        if (!$this->can_use_dbal_for_sql($sql, $params)) {
            $result = $this->legacyEngine->exec($sql, $transaction, $params);
            self::$last_affected_rows = $this->legacyEngine->affected_rows();
            return $result;
        }

        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        self::$core_log->new_sql($sql);
        self::$last_affected_rows = 0;

        $transaction_started = FALSE;
        if ($transaction) {
            $transaction_started = $this->begin_transaction();
            if (!$transaction_started) {
                if (empty(self::$last_error)) {
                    $this->log_error_message('No se pudo iniciar la transacción DBAL.');
                }

                return FALSE;
            }
        }

        try {
            self::$last_affected_rows = (int) $connection->executeStatement($sql, $params);
            $result = TRUE;
        } catch (\Throwable $e) {
            $result = $this->log_exception($e);
        }

        if ($transaction_started) {
            $result ? $this->commit() : $this->rollback();
        }

        return $result;
    }

    public function generate_table($table_name, $xml_cols, $xml_cons)
    {
        return $this->legacyEngine->generate_table($table_name, $xml_cols, $xml_cons);
    }

    public function get_columns($table_name)
    {
        return $this->legacyEngine->get_columns($table_name);
    }

    public function get_constraints($table_name)
    {
        return $this->legacyEngine->get_constraints($table_name);
    }

    public function get_constraints_extended($table_name)
    {
        return $this->legacyEngine->get_constraints_extended($table_name);
    }

    public function get_indexes($table_name)
    {
        return $this->legacyEngine->get_indexes($table_name);
    }

    public function get_locks()
    {
        return $this->legacyEngine->get_locks();
    }

    public function lastval()
    {
        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        try {
            $sql = fs_dbal::database_type() === 'POSTGRESQL'
                ? 'SELECT lastval() AS num;'
                : 'SELECT LAST_INSERT_ID() AS num;';

            return $connection->fetchOne($sql);
        } catch (\Throwable $e) {
            $this->log_exception($e);
            return FALSE;
        }
    }

    public function list_tables()
    {
        return $this->legacyEngine->list_tables();
    }

    public function rollback()
    {
        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        try {
            $connection->rollBack();
            return TRUE;
        } catch (\Throwable $e) {
            return $this->log_exception($e);
        }
    }

    public function select($sql, $params = [])
    {
        if (!$this->can_use_dbal_for_sql($sql, $params)) {
            return $this->legacyEngine->select($sql, $params);
        }

        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        self::$core_log->new_sql($sql);

        try {
            if (method_exists($connection, 'fetchAllAssociative')) {
                $result = $connection->fetchAllAssociative($sql, $params);
            } else {
                $queryResult = $connection->executeQuery($sql, $params);
                $result = $queryResult->fetchAllAssociative();
            }

            self::$t_selects++;
            return $result;
        } catch (\Throwable $e) {
            $this->log_exception($e);
            self::$t_selects++;
            return FALSE;
        }
    }

    public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = [])
    {
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);

        if ($limit > self::MAX_SELECT_LIMIT) {
            $limit = self::MAX_SELECT_LIMIT;
        }

        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset . ';';
        return $this->select($sql, $params);
    }

    public function sql_to_int($col_name)
    {
        return $this->legacyEngine->sql_to_int($col_name);
    }

    public function get_error_msg()
    {
        return self::$last_error;
    }

    public function version()
    {
        $connection = $this->get_connection();
        if (!$connection) {
            return FALSE;
        }

        try {
            $version = (string) $connection->fetchOne('SELECT version()');
            return fs_dbal::database_type() . ' ' . $version;
        } catch (\Throwable $e) {
            $this->log_exception($e);
            return FALSE;
        }
    }

    /**
     * @return fs_mysql|fs_postgresql
     */
    private function create_legacy_engine()
    {
        return fs_dbal::database_type() === 'POSTGRESQL' ? new fs_postgresql() : new fs_mysql();
    }

    /**
     * @return object|null
     */
    private function get_connection()
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        return $this->connect() ? self::$connection : null;
    }

    /**
     * Determina si una sentencia es segura para el primer slice DBAL.
     *
     * En esta fase evitamos multi-statement y delegamos esos casos al motor legacy.
     *
     * @param string $sql
     * @param array $params
     * @return bool
     */
    private function can_use_dbal_for_sql($sql, $params)
    {
        $trimmed = trim((string) $sql);
        if ($trimmed === '') {
            return TRUE;
        }

        $withoutFinalSemicolon = rtrim($trimmed, "; \t\n\r\0\x0B");
        if (strpos($withoutFinalSemicolon, ';') !== FALSE) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * @param \Throwable $e
     * @return bool
     */
    private function log_exception($e)
    {
        self::$last_error = $e->getMessage();
        self::$core_log->new_error(self::$last_error);
        self::$core_log->save(self::$last_error);
        return FALSE;
    }

    /**
     * @param string $message
     * @return bool
     */
    private function log_error_message($message)
    {
        self::$last_error = $message;
        self::$core_log->new_error(self::$last_error);
        self::$core_log->save(self::$last_error);
        return FALSE;
    }
}