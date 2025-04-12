<?php

namespace FSFramework\Service\Database\Engine;

/**
 * Clase para conectar a MySQL.
 */
class MySQLEngine extends AbstractDatabaseEngine
{
    /**
     * Inicia una transacción SQL.
     */
    public function beginTransaction(): bool
    {
        if (self::$link) {
            self::$tTransactions++;
            return self::$link->begin_transaction();
        }

        return false;
    }

    /**
     * Realiza comprobaciones extra a la tabla.
     */
    public function checkTableAux(string $tableName): bool
    {
        if (self::$link) {
            $result = self::$link->query("CHECK TABLE " . $tableName);
            if ($result) {
                $status = $result->fetch_array();
                if ($status['Msg_type'] == 'status' && $status['Msg_text'] == 'OK') {
                    return true;
                }

                self::$coreLog->newError('Error al comprobar la tabla ' . $tableName . ': ' . $status['Msg_text']);
            }
        }

        return false;
    }

    /**
     * Desconecta de la base de datos.
     */
    public function close(): bool
    {
        if (self::$link) {
            if (self::$link->close()) {
                self::$link = null;
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * Guarda los cambios de una transacción SQL.
     */
    public function commit(): bool
    {
        if (self::$link) {
            return self::$link->commit();
        }

        return false;
    }

    /**
     * Compara los tipos de las columnas de una tabla con las definiciones XML.
     */
    public function compareColumns(string $tableName, array $xmlCols, array $dbCols): string
    {
        $sql = '';

        foreach ($xmlCols as $xmlCol) {
            $found = false;
            foreach ($dbCols as $dbCol) {
                if ($dbCol['name'] == $xmlCol['nombre']) {
                    $found = true;
                    
                    // Comparar tipos y generar SQL si es necesario
                    if ($this->compareDataTypes($xmlCol['tipo'], $dbCol['type'])) {
                        $sql .= 'ALTER TABLE ' . $tableName . ' MODIFY `' . $xmlCol['nombre'] . '` ' . $xmlCol['tipo'];
                        
                        if ($xmlCol['nulo'] == 'NO') {
                            $sql .= ' NOT NULL';
                        } else {
                            $sql .= ' NULL';
                        }
                        
                        if (isset($xmlCol['defecto'])) {
                            $sql .= ' DEFAULT ' . $this->valueToSql($xmlCol['defecto']);
                        }
                        
                        $sql .= ';';
                    }
                    
                    break;
                }
            }
            
            if (!$found) {
                // La columna no existe, hay que crearla
                $sql .= 'ALTER TABLE ' . $tableName . ' ADD `' . $xmlCol['nombre'] . '` ' . $xmlCol['tipo'];
                
                if ($xmlCol['nulo'] == 'NO') {
                    $sql .= ' NOT NULL';
                } else {
                    $sql .= ' NULL';
                }
                
                if (isset($xmlCol['defecto'])) {
                    $sql .= ' DEFAULT ' . $this->valueToSql($xmlCol['defecto']);
                }
                
                $sql .= ';';
            }
        }

        return $sql;
    }

    /**
     * Compara las restricciones de una tabla con las definiciones XML.
     */
    public function compareConstraints(string $tableName, array $xmlCons, array $dbCons, bool $deleteOnly = false): string
    {
        $sql = '';

        // Implementar la lógica para comparar restricciones
        
        return $sql;
    }

    /**
     * Conecta a la base de datos.
     */
    public function connect(): bool
    {
        $connected = false;

        if (self::$link) {
            $connected = true;
        } else if (class_exists('mysqli')) {
            self::$link = @new \mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, intval(FS_DB_PORT));

            if (self::$link->connect_error) {
                self::$coreLog->newError(self::$link->connect_error);
                self::$link = null;
            } else {
                self::$link->set_charset('utf8mb4');
                $connected = true;

                if (!FS_FOREIGN_KEYS) {
                    // Desactivamos las claves ajenas
                    $this->exec("SET foreign_key_checks = 0;");
                }

                // Desactivamos el autocommit
                self::$link->autocommit(false);
            }
        } else {
            self::$coreLog->newError('No tienes instalada la extensión de PHP para MySQL.');
        }

        return $connected;
    }

    /**
     * Devuelve el estilo de fecha del motor de base de datos.
     */
    public function dateStyle(): string
    {
        return 'DD-MM-YYYY';
    }

    /**
     * Escapa las comillas de la cadena de texto.
     */
    public function escapeString(string $str): string
    {
        if (self::$link) {
            return self::$link->real_escape_string($str);
        }

        return addslashes($str);
    }

    /**
     * Ejecuta sentencias SQL sobre la base de datos (inserts, updates o deletes).
     */
    public function exec(string $sql, bool $transaction = true): bool
    {
        $result = false;

        // Añadimos la consulta al historial
        $this->addToHistory($sql);

        if (self::$link) {
            if ($transaction) {
                self::$tTransactions++;
                self::$link->begin_transaction();
            }

            $result = (bool) self::$link->query($sql);
            if (!$result) {
                self::$coreLog->newError(self::$link->error . '. La consulta SQL es: ' . $sql);
                
                if ($transaction) {
                    self::$link->rollback();
                }
            } else if ($transaction) {
                self::$link->commit();
            }
        }

        return $result;
    }

    /**
     * Devuelve la sentencia sql necesaria para crear una tabla con la estructura proporcionada.
     */
    public function generateTable(string $tableName, array $xmlCols, array $xmlCons): string
    {
        $sql = 'CREATE TABLE ' . $tableName . ' (';

        $coma = '';
        foreach ($xmlCols as $col) {
            $sql .= $coma . '`' . $col['nombre'] . '` ' . $col['tipo'];

            if ($col['nulo'] == 'NO') {
                $sql .= ' NOT NULL';
            } else {
                $sql .= ' NULL';
            }

            if (isset($col['defecto'])) {
                $sql .= ' DEFAULT ' . $this->valueToSql($col['defecto']);
            }

            $coma = ', ';
        }

        foreach ($xmlCons as $res) {
            $sql .= $coma . $res['consulta'];
            $coma = ', ';
        }

        $sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        return $sql;
    }

    /**
     * Devuelve un array con las columnas de una tabla dada.
     */
    public function getColumns(string $tableName): array
    {
        $columns = [];

        $aux = $this->select("SHOW COLUMNS FROM " . $tableName . ";");
        if ($aux) {
            foreach ($aux as $a) {
                $columns[] = [
                    'name' => $a['Field'],
                    'type' => $a['Type'],
                    'default' => $a['Default'],
                    'is_nullable' => $a['Null'],
                    'extra' => $a['Extra']
                ];
            }
        }

        return $columns;
    }

    /**
     * Devuelve una array con las restricciones de una tabla dada.
     */
    public function getConstraints(string $tableName): array
    {
        $constraints = [];

        $aux = $this->select("SHOW INDEXES FROM " . $tableName . ";");
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = [
                    'name' => $a['Key_name'],
                    'column_name' => $a['Column_name']
                ];
            }
        }

        return $constraints;
    }

    /**
     * Devuelve una array con las restricciones extendidas de una tabla dada.
     */
    public function getConstraintsExtended(string $tableName): array
    {
        $constraints = [];

        // Obtener las claves primarias
        $aux = $this->select("SHOW KEYS FROM " . $tableName . " WHERE Key_name = 'PRIMARY';");
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = [
                    'name' => 'PRIMARY',
                    'type' => 'PRIMARY KEY',
                    'column_name' => $a['Column_name']
                ];
            }
        }

        // Obtener las claves foráneas
        $aux = $this->select("SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '" . FS_DB_NAME . "' AND TABLE_NAME = '" . $tableName . "' AND REFERENCED_TABLE_NAME IS NOT NULL;");
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = [
                    'name' => $a['CONSTRAINT_NAME'],
                    'type' => 'FOREIGN KEY',
                    'column_name' => $a['COLUMN_NAME'],
                    'referenced_table_name' => $a['REFERENCED_TABLE_NAME'],
                    'referenced_column_name' => $a['REFERENCED_COLUMN_NAME']
                ];
            }
        }

        return $constraints;
    }

    /**
     * Devuelve una array con los indices de una tabla dada.
     */
    public function getIndexes(string $tableName): array
    {
        $indexes = [];

        $aux = $this->select("SHOW INDEXES FROM " . $tableName . ";");
        if ($aux) {
            foreach ($aux as $a) {
                $indexes[] = [
                    'name' => $a['Key_name'],
                    'column_name' => $a['Column_name'],
                    'unique' => ($a['Non_unique'] == 0)
                ];
            }
        }

        return $indexes;
    }

    /**
     * Devuelve un array con los bloqueos de la base de datos.
     */
    public function getLocks(): array
    {
        $locks = [];

        $aux = $this->select("SHOW OPEN TABLES WHERE In_use > 0;");
        if ($aux) {
            foreach ($aux as $a) {
                $locks[] = [
                    'table' => $a['Table'],
                    'in_use' => $a['In_use']
                ];
            }
        }

        return $locks;
    }

    /**
     * Devuleve el último ID asignado al hacer un INSERT en la base de datos.
     */
    public function lastval(): int
    {
        if (self::$link) {
            return (int) self::$link->insert_id;
        }

        return 0;
    }

    /**
     * Devuelve un array con los nombres de las tablas de la base de datos.
     */
    public function listTables(): array
    {
        $tables = [];

        $aux = $this->select("SHOW TABLES;");
        if ($aux) {
            foreach ($aux as $a) {
                $tables[] = [
                    'name' => current($a)
                ];
            }
        }

        return $tables;
    }

    /**
     * Deshace los cambios de una transacción SQL.
     */
    public function rollback(): bool
    {
        if (self::$link) {
            return self::$link->rollback();
        }

        return false;
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, y devuelve un array con los resultados,
     * o false en caso de fallo.
     */
    public function select(string $sql): array|false
    {
        $result = false;

        // Añadimos la consulta al historial
        $this->addToHistory($sql);

        if (self::$link) {
            self::$tSelects++;
            $aux = self::$link->query($sql);
            
            if ($aux) {
                $result = [];
                while ($row = $aux->fetch_array(MYSQLI_ASSOC)) {
                    $result[] = $row;
                }
                $aux->free();
            } else {
                self::$coreLog->newError(self::$link->error . '. La consulta SQL es: ' . $sql);
            }
        }

        return $result;
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, pero con paginación,
     * y devuelve un array con los resultados o false en caso de fallo.
     */
    public function selectLimit(string $sql, int $limit = FS_ITEM_LIMIT, int $offset = 0): array|false
    {
        $result = false;

        // Añadimos LIMIT y OFFSET a la consulta
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        // Añadimos la consulta al historial
        $this->addToHistory($sql);

        if (self::$link) {
            self::$tSelects++;
            $aux = self::$link->query($sql);
            
            if ($aux) {
                $result = [];
                while ($row = $aux->fetch_array(MYSQLI_ASSOC)) {
                    $result[] = $row;
                }
                $aux->free();
            } else {
                self::$coreLog->newError(self::$link->error . '. La consulta SQL es: ' . $sql);
            }
        }

        return $result;
    }

    /**
     * Devuelve el SQL necesario para convertir la columna a entero.
     */
    public function sqlToInt(string $colName): string
    {
        return 'CAST(' . $colName . ' AS SIGNED)';
    }

    /**
     * Devuelve la versión del motor de base de datos.
     */
    public function version(): string
    {
        if (self::$link) {
            return 'MySQL ' . self::$link->server_info;
        }

        return 'MySQL (desconectado)';
    }

    /**
     * Compara dos tipos de datos para determinar si son compatibles
     */
    private function compareDataTypes(string $xmlType, string $dbType): bool
    {
        // Normalizar los tipos
        $xmlType = strtolower(trim($xmlType));
        $dbType = strtolower(trim($dbType));

        // Si son iguales, no hay problema
        if ($xmlType == $dbType) {
            return false;
        }

        // Comparar tipos numéricos
        if (strpos($xmlType, 'int') !== false && strpos($dbType, 'int') !== false) {
            return false;
        }

        // Comparar tipos de texto
        if ((strpos($xmlType, 'char') !== false || strpos($xmlType, 'text') !== false) &&
            (strpos($dbType, 'char') !== false || strpos($dbType, 'text') !== false)) {
            
            // Si el tipo XML es más grande que el de la BD, hay que modificarlo
            if (strpos($xmlType, 'text') !== false && strpos($dbType, 'char') !== false) {
                return true;
            }
            
            // Extraer longitudes para varchar
            if (strpos($xmlType, 'varchar') !== false && strpos($dbType, 'varchar') !== false) {
                preg_match('/varchar\((\d+)\)/', $xmlType, $xmlMatches);
                preg_match('/varchar\((\d+)\)/', $dbType, $dbMatches);
                
                if (isset($xmlMatches[1]) && isset($dbMatches[1])) {
                    return (int)$xmlMatches[1] > (int)$dbMatches[1];
                }
            }
            
            return false;
        }

        // Por defecto, si los tipos son diferentes, hay que modificar
        return true;
    }

    /**
     * Convierte un valor a formato SQL
     */
    private function valueToSql($value): string
    {
        if ($value === null) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        } elseif (is_numeric($value)) {
            return $value;
        }

        return "'" . $this->escapeString($value) . "'";
    }
}
