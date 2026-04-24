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

/**
 * Clase para conectar a MySQL.
 * 
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_mysql extends fs_db_engine
{
    private const SQL_MODIFY_COL = ' MODIFY `';
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';
    private const CURRENT_TIMESTAMP_FUNC = 'CURRENT_TIMESTAMP()';
    private const NOW_FUNC = 'NOW()';

    /**
     * El último error ocurrido.
     * @var string
     */
    private static $last_error = '';

    /**
     * Nº de filas afectadas por la última sentencia de escritura.
     * @var integer
     */
    private static $last_affected_rows = 0;


    /**
     * Inicia una transacción SQL.
     * @return boolean
     */
    public function begin_transaction()
    {
        /**
         * Ejecutamos START TRANSACTION en lugar de begin_transaction()
         * para mayor compatibilidad.
         */
        return $this->execute_transaction_command('START TRANSACTION;');
    }

    public function affected_rows()
    {
        return self::$last_affected_rows;
    }

    /**
     * Realiza comprobaciones extra a la tabla.
     * @param string $table_name
     * @return boolean
     */
    public function check_table_aux($table_name)
    {
        /// ¿La tabla no usa InnoDB?
        $data = $this->select("SHOW TABLE STATUS FROM `" . FS_DB_NAME . "` LIKE '" . $table_name . "';");
        if ($data && $data[0]['Engine'] != 'InnoDB' && !$this->exec("ALTER TABLE " . $table_name . " ENGINE=InnoDB;")) {
            self::$core_log->new_error('Imposible convertir la tabla ' . $table_name . ' a InnoDB.'
                . ' Imprescindible para FacturaScripts.');
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Desconecta de la base de datos.
     * @return boolean
     */
    public function close()
    {
        if (self::$link) {
            $return = self::$link->close();
            self::$link = NULL;
            return $return;
        }

        return TRUE;
    }

    /**
     * Guarda los cambios de una transacción SQL.
     * @return boolean
     */
    public function commit()
    {
        if ($this->execute_transaction_command('COMMIT;')) {
            /// aumentamos el contador de selects realizados
            self::$t_transactions++;
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Compara dos arrays de columnas, devuelve una sentencia SQL en caso de encontrar diferencias.
     * @param string $table_name
     * @param array $xml_cols
     * @param array $db_cols
     * @return string
     */
    public function compare_columns($table_name, $xml_cols, $db_cols)
    {
        $sql = '';
        $fk_columns = $this->get_fk_column_names($table_name);

        foreach ($xml_cols as $xml_col) {
            $xml_col['tipo'] = $this->convert_pg_type($xml_col['tipo']);
            if (strtolower($xml_col['tipo']) == 'integer') {
                $xml_col['tipo'] = FS_DB_INTEGER;
            }
            $xmlType = $xml_col['tipo'];
            $xmlDefault = $this->normalize_mysql_default($xml_col['defecto'], $xmlType);

            $db_col = $this->search_in_array($db_cols, 'name', $xml_col['nombre']);
            if (empty($db_col)) {
                $sql .= $this->buildAddColumnSql($table_name, $xml_col, $xmlType, $xmlDefault);
                continue;
            }

            $sql .= $this->buildTypeChangeSql($table_name, $xml_col, $xmlType, $db_col, $fk_columns);
            $sql .= $this->buildNullableChangeSql($table_name, $xml_col, $xmlType, $db_col, $fk_columns);
            $sql .= $this->buildDefaultChangeSql($table_name, $xml_col, $xmlType, $xmlDefault, $db_col);
        }

        return $this->fix_postgresql($sql);
    }

    private function buildAddColumnSql($table_name, $xml_col, $xmlType, $xmlDefault)
    {
        $sql = 'ALTER TABLE ' . $table_name . ' ADD `' . $xml_col['nombre'] . '` ';

        if ($xml_col['tipo'] == 'serial') {
            return $sql . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT;';
        }

        $sql .= $xmlType;
        $sql .= ($xml_col['nulo'] == 'NO') ? " NOT NULL" : " NULL";

        if ($xmlDefault !== NULL) {
            return $sql . " DEFAULT " . $xmlDefault . ";";
        }

        return $sql . (($xml_col['nulo'] == 'YES') ? " DEFAULT NULL;" : ";");
    }

    private function buildTypeChangeSql($table_name, $xml_col, $xmlType, $db_col, $fk_columns)
    {
        if ($this->compare_data_types($db_col['type'], $xmlType)) {
            return '';
        }

        if (in_array($xml_col['nombre'], $fk_columns) && !$this->column_type_really_differs($db_col['type'], $xmlType)) {
            return '';
        }

        return 'ALTER TABLE ' . $table_name . self::SQL_MODIFY_COL . $xml_col['nombre'] . '` ' . $xmlType . ';';
    }

    private function buildNullableChangeSql($table_name, $xml_col, $xmlType, $db_col, $fk_columns)
    {
        if ($db_col['is_nullable'] == $xml_col['nulo']) {
            return '';
        }

        if (in_array($xml_col['nombre'], $fk_columns) && !$this->column_type_really_differs($db_col['type'], $xmlType)) {
            return '';
        }

        $nullable = ($xml_col['nulo'] == 'YES') ? ' NULL;' : ' NOT NULL;';
        return 'ALTER TABLE ' . $table_name . self::SQL_MODIFY_COL . $xml_col['nombre'] . '` ' . $xmlType . $nullable;
    }

    private function buildDefaultChangeSql($table_name, $xml_col, $xmlType, $xmlDefault, $db_col)
    {
        if ($this->compare_defaults($db_col['default'], $xmlDefault)) {
            return '';
        }

        if (is_null($xmlDefault)) {
            return 'ALTER TABLE ' . $table_name . ' ALTER `' . $xml_col['nombre'] . '` DROP DEFAULT;';
        }

        if (strtolower(substr($xmlDefault, 0, 9)) == "nextval('") {
            if ($db_col['extra'] == 'auto_increment') {
                return '';
            }
            $nullable = ($xml_col['nulo'] == 'YES') ? ' NULL AUTO_INCREMENT;' : ' NOT NULL AUTO_INCREMENT;';
            return 'ALTER TABLE ' . $table_name . self::SQL_MODIFY_COL . $xml_col['nombre'] . '` ' . $xmlType . $nullable;
        }

        return 'ALTER TABLE ' . $table_name . ' ALTER `' . $xml_col['nombre'] . '` SET DEFAULT ' . $xmlDefault . ";";
    }

    /**
     * Compara dos arrays de restricciones, devuelve una sentencia SQL en caso de encontrar diferencias.
     * @param string $table_name
     * @param array $xml_cons
     * @param array $db_cons
     * @param boolean $delete_only
     * @return string
     */
    public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = FALSE)
    {
        $sql = '';
        $xmlSignatures = $this->buildXmlConstraintSignatures($xml_cons);
        $dbSignatures = $this->buildDbConstraintSignatures($table_name);

        if (!empty($db_cons)) {
            /**
             * comprobamos una a una las restricciones de la base de datos, si hay que eliminar una,
             * tendremos que eliminar todas para evitar problemas.
             */
            $delete = FALSE;
            foreach ($db_cons as $db_con) {
                if (empty($xml_cons)) {
                    $delete = TRUE;
                    break;
                }

                $found = FALSE;
                foreach ($xml_cons as $xml_con) {
                    if ($db_con['name'] == 'PRIMARY' || $db_con['name'] == $xml_con['nombre']) {
                        $found = TRUE;
                        break;
                    }
                }

                if (!$found && $this->constraintHasEquivalentXmlDefinition($db_con, $dbSignatures, $xmlSignatures)) {
                    $found = TRUE;
                }

                if (!$found) {
                    $delete = TRUE;
                    break;
                }
            }

            /// eliminamos todas las restricciones
            if ($delete) {
                /// eliminamos antes las claves ajenas y luego los unique, evita problemas
                $sql_unique = '';
                foreach ($db_cons as $db_con) {
                    if ($db_con['type'] == 'FOREIGN KEY') {
                        $sql .= 'ALTER TABLE ' . $table_name . ' DROP FOREIGN KEY ' . $db_con['name'] . ';';
                    }
                    if ($db_con['type'] == 'UNIQUE') {
                        $sql_unique .= 'ALTER TABLE ' . $table_name . ' DROP INDEX ' . $db_con['name'] . ';';
                    }
                }

                $sql .= $sql_unique;
                $db_cons = [];
            }
        }

        if (!empty($xml_cons) && !$delete_only && FS_FOREIGN_KEYS) {
            /// comprobamos una a una las nuevas
            foreach ($xml_cons as $xml_con) {
                $db_con = $this->search_in_array($db_cons, 'name', $xml_con['nombre']);
                if (!empty($db_con)) {
                    continue;
                }

                if ($this->xmlConstraintHasEquivalentDbDefinition($xml_con, $dbSignatures, $xmlSignatures)) {
                    continue;
                } elseif (substr($xml_con['consulta'], 0, 11) == 'FOREIGN KEY') {
                    $sql .= 'ALTER TABLE ' . $table_name . ' ADD CONSTRAINT ' . $xml_con['nombre'] . ' ' . $xml_con['consulta'] . ';';
                } else if (substr($xml_con['consulta'], 0, 6) == 'UNIQUE') {
                    $sql .= 'ALTER TABLE ' . $table_name . ' ADD CONSTRAINT ' . $xml_con['nombre'] . ' ' . $xml_con['consulta'] . ';';
                }
            }
        }

        return $this->fix_postgresql($sql);
    }

    private function constraintHasEquivalentXmlDefinition(array $dbConstraint, array $dbSignatures, array $xmlSignatures): bool
    {
        if (empty($dbConstraint['name']) || !isset($dbSignatures[$dbConstraint['name']])) {
            return false;
        }

        return in_array($dbSignatures[$dbConstraint['name']], $xmlSignatures, true);
    }

    private function xmlConstraintHasEquivalentDbDefinition(array $xmlConstraint, array $dbSignatures, array $xmlSignatures): bool
    {
        if (empty($xmlConstraint['nombre']) || !isset($xmlSignatures[$xmlConstraint['nombre']])) {
            return false;
        }

        return in_array($xmlSignatures[$xmlConstraint['nombre']], $dbSignatures, true);
    }

    private function buildXmlConstraintSignatures(array $xmlConstraints): array
    {
        $signatures = [];

        foreach ($xmlConstraints as $xmlConstraint) {
            if (empty($xmlConstraint['nombre']) || empty($xmlConstraint['consulta'])) {
                continue;
            }

            $signature = $this->normalizeXmlConstraintSignature($xmlConstraint['consulta']);
            if ($signature !== null) {
                $signatures[$xmlConstraint['nombre']] = $signature;
            }
        }

        return $signatures;
    }

    private function buildDbConstraintSignatures(string $table_name): array
    {
        $grouped = [];
        foreach ($this->get_constraints_extended($table_name) as $row) {
            if (empty($row['name']) || empty($row['type'])) {
                continue;
            }

            $name = $row['name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'type' => strtoupper($row['type']),
                    'rows' => [],
                ];
            }

            $grouped[$name]['rows'][] = $row;
        }

        $signatures = [];
        foreach ($grouped as $name => $constraint) {
            $signature = $this->normalizeDbConstraintSignature($constraint);
            if ($signature !== null) {
                $signatures[$name] = $signature;
            }
        }

        return $signatures;
    }

    private function normalizeXmlConstraintSignature(string $query): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($query));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)$/i', $normalized, $matches)) {
            return 'PRIMARY KEY|' . implode(',', $this->normalizeIdentifierList($matches[1]));
        }

        if (preg_match('/^UNIQUE\s*\(([^)]+)\)$/i', $normalized, $matches)) {
            return 'UNIQUE|' . implode(',', $this->normalizeIdentifierList($matches[1]));
        }

        if (!preg_match('/^FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+([^\s(]+)\s*\(([^)]+)\)(.*)$/i', $normalized, $matches)) {
            return null;
        }

        $localColumns = implode(',', $this->normalizeIdentifierList($matches[1]));
        $foreignTable = $this->normalizeIdentifier($matches[2]);
        $foreignColumns = implode(',', $this->normalizeIdentifierList($matches[3]));
        $tail = $matches[4] ?? '';

        return sprintf(
            'FOREIGN KEY|%s|%s|%s|%s|%s',
            $localColumns,
            $foreignTable,
            $foreignColumns,
            $this->extractRuleFromSqlTail($tail, 'UPDATE'),
            $this->extractRuleFromSqlTail($tail, 'DELETE')
        );
    }

    private function normalizeDbConstraintSignature(array $constraint): ?string
    {
        if (empty($constraint['type']) || empty($constraint['rows'])) {
            return null;
        }

        $rows = $constraint['rows'];
        usort($rows, static function (array $left, array $right): int {
            $leftPosition = (int) ($left['ordinal_position'] ?? 0);
            $rightPosition = (int) ($right['ordinal_position'] ?? 0);

            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            return strcmp((string) ($left['column_name'] ?? ''), (string) ($right['column_name'] ?? ''));
        });

        $type = strtoupper($constraint['type']);
        $columns = [];
        foreach ($rows as $row) {
            if (!empty($row['column_name'])) {
                $columns[] = $this->normalizeIdentifier($row['column_name']);
            }
        }

        if ($type === 'PRIMARY KEY' || $type === 'UNIQUE') {
            return $type . '|' . implode(',', $columns);
        }

        if ($type !== 'FOREIGN KEY') {
            return null;
        }

        $firstRow = $rows[0];
        $foreignColumns = [];
        foreach ($rows as $row) {
            if (!empty($row['foreign_column_name'])) {
                $foreignColumns[] = $this->normalizeIdentifier($row['foreign_column_name']);
            }
        }

        return sprintf(
            'FOREIGN KEY|%s|%s|%s|%s|%s',
            implode(',', $columns),
            $this->normalizeIdentifier((string) ($firstRow['foreign_table_name'] ?? '')),
            implode(',', $foreignColumns),
            strtoupper((string) ($firstRow['on_update'] ?? 'RESTRICT')),
            strtoupper((string) ($firstRow['on_delete'] ?? 'RESTRICT'))
        );
    }

    private function normalizeIdentifierList(string $list): array
    {
        $items = array_map('trim', explode(',', $list));
        $items = array_filter($items, static function ($item): bool {
            return $item !== '';
        });

        return array_map(function ($item): string {
            return $this->normalizeIdentifier($item);
        }, $items);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, "`\" ");

        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier);
            $identifier = end($parts);
            $identifier = trim((string) $identifier, "`\" ");
        }

        return strtolower($identifier);
    }

    private function extractRuleFromSqlTail(string $tail, string $ruleType): string
    {
        if (!preg_match('/ON ' . $ruleType . '\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $tail, $matches)) {
            return 'RESTRICT';
        }

        return strtoupper($matches[1]);
    }

    /**
     * Conecta a la base de datos.
     * @return boolean
     */
    public function connect()
    {
        if (self::$link) {
            return TRUE;
        }

        if (!class_exists('mysqli')) {
            self::$core_log->new_error('No tienes instalada la extensión de PHP para MySQL.');
            return FALSE;
        }

        return $this->initializeConnection();
    }

    private function initializeConnection()
    {
        self::$link = @new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, intval(FS_DB_PORT));

        if (self::$link->connect_error) {
            $error_msg = 'Error de conexión MySQL (' . self::$link->connect_errno . ') ' . self::$link->connect_error;
            self::$core_log->new_error($error_msg);
            self::logDebug($error_msg);
            self::$link = NULL;
            return FALSE;
        }

        self::$link->set_charset('utf8');
        self::logDebug('Connected to database ' . FS_DB_NAME . ' on ' . FS_DB_HOST);

        if (!FS_FOREIGN_KEYS) {
            $this->exec("SET foreign_key_checks = 0;");
        }

        self::$link->autocommit(FALSE);
        return TRUE;
    }

    private static function logDebug(string $message): void
    {
        if (defined('FS_DEBUG') && FS_DEBUG) {
            error_log('FS_DEBUG: ' . $message);
        }
    }

    /**
     * Devuelve el estilo de fecha del motor de base de datos.
     * @return string
     */
    public function date_style()
    {
        return 'Y-m-d';
    }

    /**
     * Escapa las comillas de la cadena de texto.
     * @param string $str
     * @return string
     */
    public function escape_string($str)
    {
        return self::$link ? self::$link->escape_string($str) : $str;
    }

    /**
     * Ejecuta sentencias SQL sobre la base de datos (inserts, updates y deletes).
     * Para selects, mejor usar las funciones select() o select_limit().
     * Por defecto se inicia una transacción, se ejecutan las consultas, y si todo
     * sale bien, se guarda, sino se deshace.
     * Se puede evitar este modo de transacción si se pone false
     * en el parametro transaction.
     * @param string $sql
     * @param boolean $transaction
     * @return boolean
     */
    public function exec($sql, $transaction = TRUE, $params = [])
    {
        if (!self::$link) {
            $this->connect();
        }

        if (!self::$link) {
            return FALSE;
        }

        self::$core_log->new_sql($sql);
        self::$last_affected_rows = 0;
        if ($transaction) {
            $this->begin_transaction();
        }

        $queryIndex = 0;
        $affectedRows = 0;
        $result = $this->execute_statement($sql, $params, $queryIndex, $affectedRows);
        self::$last_affected_rows = $affectedRows;

        if (self::$link->errno && !$result) {
            self::$last_error = self::$link->error;
            $this->log_exec_error($queryIndex, self::$last_error);
        } elseif ($result) {
            $result = TRUE;
        }

        if ($transaction) {
            $result ? $this->commit() : $this->rollback();
        }

        return $result;
    }

    private function execute_statement($sql, $params, &$queryIndex, &$affectedRows)
    {
        try {
            $queryIndex = 1;

            if (!empty($params) && method_exists(self::$link, 'execute_query')) {
                $result = self::$link->execute_query($sql, $params);
                $affectedRows = $result !== FALSE ? (int) self::$link->affected_rows : -1;
                return $result !== FALSE;
            }

            if (!self::$link->multi_query($sql)) {
                $affectedRows = -1;
                return FALSE;
            }

            return $this->consume_multi_query_results($queryIndex, $affectedRows);
        } catch (mysqli_sql_exception $e) {
            self::$last_error = $e->getMessage();
            $affectedRows = -1;
            $this->log_exec_error($queryIndex, self::$last_error);
            return FALSE;
        }
    }

    private function consume_multi_query_results(&$queryIndex, &$affectedRows)
    {
        $totalAffectedRows = 0;

        while (TRUE) {
            $currentAffectedRows = (int) self::$link->affected_rows;
            $storedResult = self::$link->store_result();
            if ($storedResult !== FALSE) {
                if ($currentAffectedRows === -1 && property_exists($storedResult, 'num_rows')) {
                    $currentAffectedRows = (int) $storedResult->num_rows;
                }

                $storedResult->free();
            } elseif (self::$link->errno) {
                $affectedRows = -1;
                return FALSE;
            }

            if ($currentAffectedRows === -1) {
                $affectedRows = -1;
                return FALSE;
            }

            $totalAffectedRows += $currentAffectedRows;

            if (!self::$link->more_results()) {
                $affectedRows = $totalAffectedRows;
                return TRUE;
            }

            $nextQueryIndex = $queryIndex + 1;
            if (!self::$link->next_result()) {
                $queryIndex = $nextQueryIndex;
                $affectedRows = -1;
                return FALSE;
            }

            $queryIndex = $nextQueryIndex;
        }
    }

    private function log_exec_error($queryIndex, $message)
    {
        $error = 'Error al ejecutar la consulta ' . $queryIndex . ': ' . $message
            . '. La secuencia ocupa la posición ' . count(self::$core_log->get_sql_history());
        self::$core_log->new_error($error);
        self::$core_log->save($error);
    }

    /**
     * Mapeo de tipos PostgreSQL a MySQL para generate_table().
     * @var array
     */
    private static $pgToMysqlTypes = [
        'character varying' => 'VARCHAR',
        'character' => 'CHAR',
        'boolean' => 'TINYINT(1)',
        'double precision' => 'DOUBLE',
        'real' => 'FLOAT',
        'bytea' => 'BLOB',
    ];

    /**
     * Devuelve la sentencia SQL necesaria para crear una tabla con la estructura proporcionada.
     * @param string $table_name
     * @param array $xml_cols
     * @param array $xml_cons
     * @return string
     */
    public function generate_table($table_name, $xml_cols, $xml_cons)
    {
        $fkCollations = $this->get_fk_column_collations($xml_cons);
        $sql = "CREATE TABLE IF NOT EXISTS " . $table_name . " ( ";

        $i = FALSE;
        foreach ($xml_cols as $col) {
            if ($i) {
                $sql .= ", ";
            } else {
                $i = TRUE;
            }

            $col['tipo'] = $this->convert_pg_type($col['tipo']);

            if ($col['tipo'] == 'serial') {
                $sql .= '`' . $col['nombre'] . '` ' . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT';
            } else {
                if (strtolower($col['tipo']) == 'integer') {
                    $col['tipo'] = FS_DB_INTEGER;
                }

                $sql .= '`' . $col['nombre'] . '` ' . $col['tipo'];

                $columnName = isset($col['nombre']) ? $col['nombre'] : '';
                if (isset($fkCollations[$columnName]) && $this->is_collatable_column_type($col['tipo'])) {
                    $sql .= ' CHARACTER SET ' . $fkCollations[$columnName]['charset']
                        . ' COLLATE ' . $fkCollations[$columnName]['collation'];
                }

                if ($col['nulo'] == 'NO') {
                    $sql .= " NOT NULL";
                } else {
                    $sql .= " NULL";
                }

                if ($col['defecto'] !== NULL) {
                    $sql .= " DEFAULT " . $this->normalize_mysql_default($col['defecto'], $col['tipo']);
                }
            }
        }

        $validatedCons = $this->validate_fk_constraints($xml_cons);
        return $this->fix_postgresql($sql) . ' ' . $this->generate_table_constraints($validatedCons) . ' ) '
            . $this->table_charset_collation_sql() . ';';
    }

    /**
     * Convierte un tipo PostgreSQL a su equivalente MySQL.
     *
     * @param string $type
     * @return string
     */
    private function convert_pg_type($type)
    {
        $matches = [];
        if (preg_match('/^([a-z\s]+)(?:\((\d+(?:,\d+)?)\))?$/i', trim($type), $matches)) {
            $baseType = strtolower(trim($matches[1]));
            $baseType = preg_replace('/\s+without\s+time\s+zone$/i', '', $baseType);
            $length = isset($matches[2]) ? $matches[2] : null;

            foreach (self::$pgToMysqlTypes as $pgType => $mysqlType) {
                if ($baseType === $pgType || strpos($baseType, $pgType) === 0) {
                    if ($length && strpos($mysqlType, '(') === false) {
                        return "{$mysqlType}({$length})";
                    }
                    return $mysqlType;
                }
            }
        }

        return $type;
    }

    /**
     * Filtra las restricciones FK cuya tabla referenciada no existe aún,
     * evitando errno 150 durante la creación de la tabla.
     *
     * @param array $xml_cons
     * @return array
     */
    private function validate_fk_constraints($xml_cons)
    {
        if (empty($xml_cons)) {
            return $xml_cons;
        }

        $tables = $this->list_tables();
        $tableNames = [];
        if (is_array($tables)) {
            foreach ($tables as $t) {
                $tableNames[] = strtolower($t['name']);
            }
        }

        $validated = [];
        foreach ($xml_cons as $con) {
            if (stripos($con['consulta'], 'FOREIGN KEY') === false) {
                $validated[] = $con;
                continue;
            }

            if (preg_match('/REFERENCES\s+(?:`([^`]+)`|"([^"]+)"|([A-Za-z0-9_]+))/i', $con['consulta'], $m)) {
                $refTable = $m[1] ?: ($m[2] ?: ($m[3] ?: ''));
                $refTable = trim($refTable, '"`');

                if ($refTable !== '' && in_array(strtolower($refTable), $tableNames)) {
                    $validated[] = $con;
                } else {
                    error_log("generate_table: FK '{$con['nombre']}' omitida - tabla '{$refTable}' no existe aún.");
                }
            } else {
                $validated[] = $con;
            }
        }

        return $validated;
    }

    /**
     * Devuelve el charset/collation para las columnas locales que tienen claves foráneas,
     * tomando la configuración de la columna referenciada.
     *
     * @param array $xml_cons
     * @return array
     */
    private function get_fk_column_collations($xml_cons)
    {
        $result = [];

        if (empty($xml_cons) || !is_array($xml_cons)) {
            return $result;
        }

        foreach ($xml_cons as $cons) {
            $parsed = $this->parse_fk_collation($cons);
            if ($parsed === null) {
                continue;
            }

            $result[$parsed['localColumn']] = [
                'charset' => $parsed['charset'],
                'collation' => $parsed['collation'],
            ];
        }

        return $result;
    }

    private function parse_fk_collation(array $cons): ?array
    {
        if (empty($cons['consulta']) || stripos($cons['consulta'], 'FOREIGN KEY') === false) {
            return null;
        }

        if (!preg_match('/FOREIGN\s+KEY\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)\s*REFERENCES\s*`?([a-zA-Z0-9_]+)`?\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)/i', $cons['consulta'], $matches)) {
            return null;
        }

        $sql = "SELECT character_set_name AS charset_name, collation_name AS collation_name"
            . " FROM information_schema.columns"
            . " WHERE table_schema = DATABASE()"
            . " AND table_name = '" . $this->escape_string($matches[2]) . "'"
            . " AND column_name = '" . $this->escape_string($matches[3]) . "'"
            . " LIMIT 1;";

        $data = $this->select($sql);
        if (empty($data)) {
            return null;
        }

        $charset = isset($data[0]['charset_name']) ? strtolower($data[0]['charset_name']) : '';
        $collation = isset($data[0]['collation_name']) ? strtolower($data[0]['collation_name']) : '';

        if (!preg_match(self::IDENTIFIER_REGEX, $charset) || !preg_match(self::IDENTIFIER_REGEX, $collation)) {
            return null;
        }

        return [
            'localColumn' => $matches[1],
            'charset' => $charset,
            'collation' => $collation,
        ];
    }

    /**
     * Indica si el tipo de columna admite collation en MySQL.
     *
     * @param string $columnType
     * @return bool
     */
    private function is_collatable_column_type($columnType)
    {
        $type = strtolower(trim((string) $columnType));
        return strpos($type, 'char') !== false
            || strpos($type, 'text') !== false
            || strpos($type, 'enum(') === 0
            || strpos($type, 'set(') === 0;
    }

    /**
     * Devuelve la configuración de charset/collation para crear tablas,
     * priorizando la configuración real de la base de datos.
     *
     * @return string
     */
    private function table_charset_collation_sql()
    {
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        $dbConf = $this->select("SELECT @@character_set_database AS db_charset, @@collation_database AS db_collation;");
        if (!empty($dbConf)) {
            $dbCharset = isset($dbConf[0]['db_charset']) ? $dbConf[0]['db_charset'] : '';
            $dbCollation = isset($dbConf[0]['db_collation']) ? $dbConf[0]['db_collation'] : '';

            if (preg_match(self::IDENTIFIER_REGEX, $dbCharset)) {
                $charset = strtolower($dbCharset);
            }

            if (preg_match(self::IDENTIFIER_REGEX, $dbCollation)) {
                $collation = strtolower($dbCollation);
            }
        }

        return 'ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collation;
    }

    /**
     * Devuelve un array con las columnas de una tabla dada.
     * @param string $table_name
     * @return array
     */
    public function get_columns($table_name)
    {
        $columns = [];
        $aux = $this->select("SHOW COLUMNS FROM `" . $table_name . "`;");
        if ($aux) {
            foreach ($aux as $a) {
                $columns[] = array(
                    'name' => $a['Field'],
                    'type' => $a['Type'],
                    'default' => $a['Default'],
                    'is_nullable' => $a['Null'],
                    'extra' => $a['Extra']
                );
            }
        }

        return $columns;
    }

    /**
     * Devuelve una array con las restricciones de una tabla dada:
     * clave primaria, claves ajenas, etc.
     * @param string $table_name
     * @return array
     */
    public function get_constraints($table_name)
    {
        $constraints = [];
        $sql = "SELECT CONSTRAINT_NAME as name, CONSTRAINT_TYPE as type FROM information_schema.table_constraints "
            . "WHERE table_schema = schema() AND table_name = '" . $table_name . "';";

        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = $a;
            }
        }

        return $constraints;
    }

    /**
     * Devuelve una array con las restricciones de una tabla dada, pero aportando muchos más detalles.
     * @param string $table_name
     * @return array
     */
    public function get_constraints_extended($table_name)
    {
        $constraints = [];
        $sql = "SELECT t1.constraint_name as name,
            t1.constraint_type as type,
            t2.column_name,
            t2.ordinal_position,
            t2.position_in_unique_constraint,
            t2.referenced_table_name AS foreign_table_name,
            t2.referenced_column_name AS foreign_column_name,
            t3.update_rule AS on_update,
            t3.delete_rule AS on_delete
         FROM information_schema.table_constraints t1
         LEFT JOIN information_schema.key_column_usage t2
            ON t1.table_schema = t2.table_schema
            AND t1.table_name = t2.table_name
            AND t1.constraint_name = t2.constraint_name
         LEFT JOIN information_schema.referential_constraints t3
            ON t3.constraint_schema = t1.table_schema
            AND t3.constraint_name = t1.constraint_name
         WHERE t1.table_schema = SCHEMA() AND t1.table_name = '" . $table_name . "'
            ORDER BY type DESC, name ASC, t2.ordinal_position ASC;";

        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = $a;
            }
        }

        return $constraints;
    }

    /**
     * Devuelve una array con los indices de una tabla dada.
     * @param string $table_name
     * @return array
     */
    public function get_indexes($table_name)
    {
        $indexes = [];
        $aux = $this->select("SHOW INDEXES FROM " . $table_name . ";");
        if ($aux) {
            foreach ($aux as $a) {
                $indexes[] = array('name' => $a['Key_name']);
            }
        }

        return $indexes;
    }

    /**
     * Devuelve un array con los datos de bloqueos en la base de datos.
     * @return array
     */
    public function get_locks()
    {
        return [];
    }

    /**
     * Devuleve el último ID asignado al hacer un INSERT en la base de datos.
     * @return integer|false
     */
    public function lastval()
    {
        $aux = $this->select('SELECT LAST_INSERT_ID() as num;');
        return $aux ? $aux[0]['num'] : FALSE;
    }

    /**
     * Devuelve un array con los nombres de las tablas de la base de datos.
     * @return array
     */
    public function list_tables()
    {
        $tables = [];
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = '" . FS_DB_NAME . "';";
        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $tables[] = array('name' => $a['table_name']);
            }
        }

        if (defined('FS_DEBUG') && FS_DEBUG) {
            error_log("FS_DEBUG: list_tables() for '" . FS_DB_NAME . "' returned " . count($tables) . " tables");
        }
        return $tables;
    }

    /**
     * Deshace los cambios de una transacción SQL.
     * @return boolean
     */
    public function rollback()
    {
        return $this->execute_transaction_command('ROLLBACK;');
    }

    /**
     * Ejecuta una orden de control transaccional y reintenta una vez si MySQL
     * ha cerrado la conexión durante procesos largos como actualizaciones.
     *
     * @param string $sql
     * @return bool
     */
    private function execute_transaction_command($sql)
    {
        if (!self::$link && !$this->connect()) {
            return FALSE;
        }

        try {
            return (bool) self::$link->query($sql);
        } catch (mysqli_sql_exception $e) {
            self::$last_error = $e->getMessage();

            if ($this->should_retry_after_disconnect($e)) {
                $this->reset_connection();

                if ($this->connect()) {
                    try {
                        return (bool) self::$link->query($sql);
                    } catch (mysqli_sql_exception $retryException) {
                        self::$last_error = $retryException->getMessage();
                    }
                }
            }

            self::$core_log->new_error(self::$last_error);
            return FALSE;
        }
    }

    /**
     * Identifica errores de conexión que admiten un reintento transparente.
     *
     * @param mysqli_sql_exception $exception
     * @return bool
     */
    private function should_retry_after_disconnect(mysqli_sql_exception $exception)
    {
        $message = strtolower($exception->getMessage());
        $code = (int) $exception->getCode();

        return in_array($code, [2006, 2013], TRUE)
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection');
    }

    /**
     * Descarta el enlace actual para forzar una nueva conexión limpia.
     *
     * @return void
     */
    private function reset_connection()
    {
        if (self::$link) {
            try {
                self::$link->close();
            } catch (Throwable $e) {
            }
        }

        self::$link = NULL;
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, y devuelve un array con los resultados,
     * o false en caso de fallo.
     * @param string $sql
     * @return array
     */
    public function select($sql, $params = [])
    {
        if (!self::$link) {
            $this->connect();
        }

        if (!self::$link) {
            return FALSE;
        }

        self::$core_log->new_sql($sql);
        $aux = $this->executeSelectQuery($sql, $params);
        self::$t_selects++;

        return $this->processSelectResult($aux, $sql);
    }

    private function executeSelectQuery($sql, $params)
    {
        try {
            if (!empty($params) && method_exists(self::$link, 'execute_query')) {
                return self::$link->execute_query($sql, $params);
            }
            return self::$link->query($sql);
        } catch (mysqli_sql_exception $e) {
            self::$last_error = $e->getMessage();
            self::logDebug("Query EXCEPTION: $sql Error: " . self::$last_error);
            return FALSE;
        }
    }

    private function processSelectResult($aux, $sql)
    {
        if ($aux) {
            $result = [];
            while ($row = $aux->fetch_array(MYSQLI_ASSOC)) {
                $result[] = $row;
            }
            if (is_object($aux)) {
                $aux->free();
            }
            return $result;
        }

        self::$last_error = self::$link->error;
        self::logDebug("Query FAILED: $sql Error: " . self::$last_error);
        self::$core_log->new_error(self::$last_error);
        self::$core_log->save(self::$last_error);
        return FALSE;
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, pero con paginación,
     * y devuelve un array con los resultados,
     * o false en caso de fallo.
     * Limit es el número de elementos que quieres que devuelve.
     * Offset es el número de resultado desde el que quieres que empiece.
     * @param string $sql
     * @param integer $limit
     * @param integer $offset
     * @return array
     */
    public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = [])
    {
        /// añadimos limit y offset a la consulta sql
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset . ';';
        return $this->select($sql, $params);
    }

    /**
     * Devuelve el SQL necesario para convertir la columna a entero.
     * @param string $col_name
     * @return string
     */
    public function sql_to_int($col_name)
    {
        return 'CAST(' . $col_name . ' as UNSIGNED)';
    }

    /**
     * Devuelve el motor de base de datos y la versión.
     * @return string
     */
    public function version()
    {
        return self::$link ? 'MYSQL ' . self::$link->server_version : FALSE;
    }

    /**
     * Devuelve el último error de la base de datos.
     * @return string
     */
    public function get_error_msg()
    {
        return self::$last_error ?: (self::$link ? self::$link->error : 'Link no disponible');
    }

    /**
     * Compara los tipos de datos de una columna. Devuelve TRUE si son iguales.
     * @param string $db_type
     * @param string $xml_type
     * @return boolean
     */
    private function compare_data_types($db_type, $xml_type)
    {
        if (FS_CHECK_DB_TYPES != 1) {
            return TRUE;
        }

        // Comparación case-insensitive para evitar falsos positivos varchar vs VARCHAR
        $db_lower = strtolower($db_type);
        $xml_lower = strtolower($xml_type);

        if ($db_lower == $xml_lower || $xml_lower == 'serial') {
            return TRUE;
        }

        if ($db_lower == 'tinyint(1)' && $xml_lower == 'boolean') {
            return TRUE;
        }

        if (substr($db_lower, 0, 3) == 'int' && $xml_lower == 'integer') {
            return TRUE;
        }

        if (substr($db_lower, 0, 6) == 'double' && $xml_lower == 'double precision') {
            return TRUE;
        }

        if (substr($db_lower, 0, 4) == 'time' && substr($xml_lower, 0, 4) == 'time') {
            return TRUE;
        }

        if (
            $this->same_character_length($db_lower, $xml_lower, 'varchar(', 8)
            || $this->same_character_length($db_lower, $xml_lower, 'char(', 5)
        ) {
            return TRUE;
        }

        return FALSE;
    }

    private function same_character_length($dbType, $xmlType, $prefix, $start)
    {
        // Soportar tanto el formato PostgreSQL original como el ya convertido a MySQL
        // PostgreSQL: character varying(20)  ->  MySQL: varchar(20) / VARCHAR(20)
        $dbType = strtolower($dbType);
        $xmlType = strtolower($xmlType);

        // Si ambos ya son formato MySQL (varchar/char), comparar directamente
        if (substr($dbType, 0, strlen($prefix)) == $prefix && substr($xmlType, 0, strlen($prefix)) == $prefix) {
            return substr($dbType, $start, -1) == substr($xmlType, $start, -1);
        }

        // Si el XML todavía está en formato PostgreSQL
        $xmlPrefix = $prefix === 'char(' ? 'character(' : 'character varying(';
        $xmlStart = strlen($xmlPrefix);

        if (substr($dbType, 0, strlen($prefix)) != $prefix || substr($xmlType, 0, $xmlStart) != $xmlPrefix) {
            return false;
        }

        return substr($dbType, $start, -1) == substr($xmlType, $xmlStart, -1);
    }

    /**
     * Obtiene los nombres de columnas que están involucradas en claves foráneas.
     * @param string $table_name
     * @return array
     */
    private function get_fk_column_names($table_name)
    {
        $columns = [];
        $sql = "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME = '" . $table_name . "' "
            . "AND REFERENCED_TABLE_NAME IS NOT NULL;";
        $data = $this->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $columns[] = $d['COLUMN_NAME'];
            }
        }
        return $columns;
    }

    /**
     * Comprueba si dos tipos de columna realmente difieren en tamaño.
     * Usado para columnas con FK donde MySQL no permite ALTER TABLE MODIFY innecesarios.
     * @param string $db_type Tipo actual en la BD (ej: varchar(20))
     * @param string $xml_type Tipo deseado del XML (ej: VARCHAR(20))
     * @return boolean TRUE si realmente son diferentes
     */
    private function column_type_really_differs($db_type, $xml_type)
    {
        $db_lower = strtolower(trim($db_type));
        $xml_lower = strtolower(trim($xml_type));

        // Si son exactamente iguales (case-insensitive), no hay diferencia real
        if ($db_lower === $xml_lower) {
            return FALSE;
        }

        // Extraer tipo base y longitud de ambos
        $db_info = $this->extract_type_info($db_lower);
        $xml_info = $this->extract_type_info($xml_lower);

        // Si el tipo base y la longitud coinciden, no hay diferencia real
        if ($db_info['base'] === $xml_info['base'] && $db_info['length'] === $xml_info['length']) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Extrae el tipo base y la longitud de un tipo de columna.
     * @param string $type Ej: varchar(20), int(11)
     * @return array ['base' => 'varchar', 'length' => '20']
     */
    private function extract_type_info($type)
    {
        $type = strtolower(trim($type));
        if (preg_match('/^([a-z\s]+)\(([^)]+)\)$/', $type, $m)) {
            return ['base' => trim($m[1]), 'length' => trim($m[2])];
        }
        return ['base' => $type, 'length' => null];
    }

    /**
     * Compara los tipos por defecto. Devuelve TRUE si son equivalentes.
     * @param string $db_default
     * @param string $xml_default
     * @return boolean
     */
    private function compare_defaults($db_default, $xml_default)
    {
        if ($db_default == $xml_default) {
            return TRUE;
        }

        if (in_array($db_default, ['0', 'false', 'FALSE'])) {
            return in_array($xml_default, ['0', 'false', 'FALSE']);
        }

        if (in_array($db_default, ['1', 'true', 'TRUE'])) {
            return in_array($xml_default, ['1', 'true', 'TRUE']);
        }

        if ($this->areDateTimeDefaultsEquivalent($db_default, $xml_default)) {
            return TRUE;
        }

        if (substr($xml_default ?? '', 0, 8) == 'nextval(') {
            return TRUE;
        }

        $db_default = str_replace(['::character varying', "'"], ['', ''], $db_default ?? '');
        $xml_default = str_replace(['::character varying', "'"], ['', ''], $xml_default ?? '');
        return ($db_default == $xml_default);
    }

    private function areDateTimeDefaultsEquivalent($db_default, $xml_default): bool
    {
        $upperDb = strtoupper((string) $db_default);
        $upperXml = strtoupper((string) $xml_default);

        if (
            in_array($upperDb, ['CURRENT_TIMESTAMP', self::CURRENT_TIMESTAMP_FUNC])
            && in_array($upperXml, [self::NOW_FUNC, 'CURRENT_TIMESTAMP', self::CURRENT_TIMESTAMP_FUNC])
        ) {
            return true;
        }

        if (
            in_array($upperDb, ['CURRENT_TIME', 'CURRENT_TIME()'])
            && in_array($upperXml, [self::NOW_FUNC, 'CURRENT_TIME', 'CURRENT_TIME()'])
        ) {
            return true;
        }

        if (
            in_array($upperDb, ['CURRENT_DATE', 'CURRENT_DATE()'])
            && in_array($upperXml, [self::NOW_FUNC, 'CURRENT_DATE', 'CURRENT_DATE()'])
        ) {
            return true;
        }

        if ($db_default === '00:00:00' && $xml_default === 'now()') {
            return true;
        }

        if ($db_default === date('Y-m-d') . ' 00:00:00' && $xml_default === 'CURRENT_TIMESTAMP') {
            return true;
        }

        return $db_default === 'CURRENT_DATE' && $xml_default === date("'Y-m-d'");
    }

    /**
     * Normaliza valores por defecto según el tipo de columna en MySQL/MariaDB.
     *
     * @param string|null $default
     * @param string $columnType
     * @return string|null
     */
    private function normalize_mysql_default($default, $columnType)
    {
        if ($default === NULL) {
            return NULL;
        }

        $default = trim((string) $default);
        if ($default === '') {
            return $default;
        }

        $type = strtolower(trim((string) $columnType));
        $type = preg_replace('/\(.*/', '', $type);
        $type = preg_replace('/\s+without\s+time\s+zone$/i', '', $type);

        $default = preg_replace('/::[a-z_][a-z0-9_ ]*/i', '', $default);
        $default = trim((string) $default);

        $upperDefault = strtoupper($default);

        $timestampResult = $this->normalizeTimestampDefault($upperDefault, $type);
        if ($timestampResult !== null) {
            return $timestampResult;
        }

        if (stripos($default, 'nextval(') === 0) {
            return $default;
        }

        if ($upperDefault === 'NULL') {
            return 'NULL';
        }

        if (in_array($type, ['bool', 'boolean', 'tinyint'])) {
            if (in_array($upperDefault, ['TRUE', '1'])) {
                return '1';
            }

            if (in_array($upperDefault, ['FALSE', '0'])) {
                return '0';
            }
        }

        if (is_numeric($default)) {
            return $default;
        }

        if (preg_match('/^\'.*\'$/s', $default)) {
            return $default;
        }

        if (preg_match('/^".*"$/s', $default)) {
            $default = substr($default, 1, -1);
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $default) . "'";
    }

    private function normalizeTimestampDefault(string $upperDefault, string $type): ?string
    {
        if (!in_array($upperDefault, [self::NOW_FUNC, 'CURRENT_TIMESTAMP', self::CURRENT_TIMESTAMP_FUNC])) {
            return null;
        }

        return match ($type) {
            'time' => 'CURRENT_TIME',
            'date' => 'CURRENT_DATE',
            default => 'CURRENT_TIMESTAMP',
        };
    }

    /**
     * Elimina código problemático de postgresql.
     * @param string $sql
     * @return string
     */
    private function fix_postgresql($sql)
    {
        $sql = str_replace(
            array('::regclass', '::character varying', '::integer'),
            array('', '', ''),
            $sql
        );

        return preg_replace('/\b(timestamp|time)\s+without\s+time\s+zone\b/i', '$1', $sql);
    }

    /**
     * Genera el SQL para establecer las restricciones proporcionadas.
     * @param array $xml_cons
     * @return string
     */
    private function generate_table_constraints($xml_cons)
    {
        $sql = '';

        if (!empty($xml_cons)) {
            foreach ($xml_cons as $res) {
                if (strstr(strtolower($res['consulta']), 'primary key')) {
                    $sql .= ', ' . $res['consulta'];
                } else if (FS_FOREIGN_KEYS || substr($res['consulta'], 0, 11) != 'FOREIGN KEY') {
                    $sql .= ', CONSTRAINT ' . $res['nombre'] . ' ' . $res['consulta'];
                }
            }
        }

        return $this->fix_postgresql($sql);
    }
}
