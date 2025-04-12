<?php

namespace FSFramework\Service\Database\Engine;

/**
 * Clase para conectar a PostgreSQL.
 */
class PostgreSQLEngine extends AbstractDatabaseEngine
{
    /**
     * Inicia una transacción SQL.
     */
    public function beginTransaction(): bool
    {
        return self::$link ? (bool) pg_query(self::$link, 'BEGIN TRANSACTION;') : false;
    }

    /**
     * Realiza comprobaciones extra a la tabla.
     */
    public function checkTableAux(string $tableName): bool
    {
        return true;
    }

    /**
     * Desconecta de la base de datos.
     */
    public function close(): bool
    {
        if (self::$link) {
            if (pg_close(self::$link)) {
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
        return self::$link ? (bool) pg_query(self::$link, 'COMMIT;') : false;
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
                        $sql .= 'ALTER TABLE ' . $tableName . ' ALTER COLUMN "' . $xmlCol['nombre'] . '" TYPE ' . $xmlCol['tipo'] . ';';
                        
                        if ($xmlCol['nulo'] == 'NO' && $dbCol['is_nullable'] != 'NO') {
                            $sql .= 'ALTER TABLE ' . $tableName . ' ALTER COLUMN "' . $xmlCol['nombre'] . '" SET NOT NULL;';
                        } elseif ($xmlCol['nulo'] != 'NO' && $dbCol['is_nullable'] == 'NO') {
                            $sql .= 'ALTER TABLE ' . $tableName . ' ALTER COLUMN "' . $xmlCol['nombre'] . '" DROP NOT NULL;';
                        }
                        
                        if (isset($xmlCol['defecto'])) {
                            $sql .= 'ALTER TABLE ' . $tableName . ' ALTER COLUMN "' . $xmlCol['nombre'] . '" SET DEFAULT ' . $this->valueToSql($xmlCol['defecto']) . ';';
                        } elseif (isset($dbCol['default'])) {
                            $sql .= 'ALTER TABLE ' . $tableName . ' ALTER COLUMN "' . $xmlCol['nombre'] . '" DROP DEFAULT;';
                        }
                    }
                    
                    break;
                }
            }
            
            if (!$found) {
                // La columna no existe, hay que crearla
                $sql .= 'ALTER TABLE ' . $tableName . ' ADD COLUMN "' . $xmlCol['nombre'] . '" ' . $xmlCol['tipo'];
                
                if ($xmlCol['nulo'] == 'NO') {
                    $sql .= ' NOT NULL';
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
        } else if (function_exists('pg_connect')) {
            self::$link = pg_connect('host=' . FS_DB_HOST . ' dbname=' . FS_DB_NAME .
                ' port=' . FS_DB_PORT . ' user=' . FS_DB_USER . ' password=' . FS_DB_PASS);
            if (self::$link) {
                $connected = true;

                // Establecemos el formato de fecha para la conexión
                pg_query(self::$link, "SET DATESTYLE TO ISO, DMY;");
            }
        } else {
            self::$coreLog->newError('No tienes instalada la extensión de PHP para PostgreSQL.');
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
            return pg_escape_string(self::$link, $str);
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
                pg_query(self::$link, 'BEGIN TRANSACTION;');
            }

            $result = (bool) pg_query(self::$link, $sql);
            if (!$result) {
                self::$coreLog->newError(pg_last_error(self::$link) . '. La consulta SQL es: ' . $sql);
                
                if ($transaction) {
                    pg_query(self::$link, 'ROLLBACK;');
                }
            } else if ($transaction) {
                pg_query(self::$link, 'COMMIT;');
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
            $sql .= $coma . '"' . $col['nombre'] . '" ' . $col['tipo'];

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

        $sql .= ');';

        return $sql;
    }

    /**
     * Devuelve un array con las columnas de una tabla dada.
     */
    public function getColumns(string $tableName): array
    {
        $columns = [];

        $sql = "SELECT column_name as name, data_type as type, is_nullable, column_default as default"
             . " FROM information_schema.columns WHERE table_name = '" . $tableName . "'"
             . " ORDER BY ordinal_position;";
        
        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $columns[] = $a;
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

        $sql = "SELECT tc.constraint_name as name, tc.constraint_type as type"
             . " FROM information_schema.table_constraints tc"
             . " WHERE tc.table_name = '" . $tableName . "';";
        
        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = $a;
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

        $sql = "SELECT tc.constraint_name as name, tc.constraint_type as type,"
             . " kcu.column_name, ccu.table_name as referenced_table_name,"
             . " ccu.column_name as referenced_column_name"
             . " FROM information_schema.table_constraints tc"
             . " LEFT JOIN information_schema.key_column_usage kcu"
             . " ON tc.constraint_name = kcu.constraint_name"
             . " LEFT JOIN information_schema.constraint_column_usage ccu"
             . " ON tc.constraint_name = ccu.constraint_name"
             . " WHERE tc.table_name = '" . $tableName . "';";
        
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
     */
    public function getIndexes(string $tableName): array
    {
        $indexes = [];

        $sql = "SELECT indexname as name, indexdef as definition"
             . " FROM pg_indexes"
             . " WHERE tablename = '" . $tableName . "';";
        
        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $indexes[] = $a;
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

        $sql = "SELECT relation::regclass as table, mode, granted"
             . " FROM pg_locks l JOIN pg_stat_activity s"
             . " ON l.pid = s.pid"
             . " WHERE relation IS NOT NULL;";
        
        $aux = $this->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $locks[] = $a;
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
            $aux = $this->select('SELECT LASTVAL() as num;');
            if ($aux) {
                return (int) $aux[0]['num'];
            }
        }

        return 0;
    }

    /**
     * Devuelve un array con los nombres de las tablas de la base de datos.
     */
    public function listTables(): array
    {
        $tables = [];

        $aux = $this->select("SELECT tablename as name FROM pg_catalog.pg_tables"
                           . " WHERE schemaname NOT IN ('pg_catalog','information_schema')"
                           . " ORDER BY tablename ASC;");
        if ($aux) {
            foreach ($aux as $a) {
                $tables[] = $a;
            }
        }

        return $tables;
    }

    /**
     * Deshace los cambios de una transacción SQL.
     */
    public function rollback(): bool
    {
        return self::$link ? (bool) pg_query(self::$link, 'ROLLBACK;') : false;
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
            $aux = pg_query(self::$link, $sql);
            
            if ($aux) {
                $result = [];
                while ($row = pg_fetch_assoc($aux)) {
                    $result[] = $row;
                }
                pg_free_result($aux);
            } else {
                self::$coreLog->newError(pg_last_error(self::$link) . '. La consulta SQL es: ' . $sql);
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
            $aux = pg_query(self::$link, $sql);
            
            if ($aux) {
                $result = [];
                while ($row = pg_fetch_assoc($aux)) {
                    $result[] = $row;
                }
                pg_free_result($aux);
            } else {
                self::$coreLog->newError(pg_last_error(self::$link) . '. La consulta SQL es: ' . $sql);
            }
        }

        return $result;
    }

    /**
     * Devuelve el SQL necesario para convertir la columna a entero.
     */
    public function sqlToInt(string $colName): string
    {
        return $colName . '::integer';
    }

    /**
     * Devuelve la versión del motor de base de datos.
     */
    public function version(): string
    {
        if (self::$link) {
            $aux = $this->select('SELECT version();');
            if ($aux) {
                return $aux[0]['version'];
            }
        }

        return 'PostgreSQL (desconectado)';
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
            if (strpos($xmlType, 'character varying') !== false && strpos($dbType, 'character varying') !== false) {
                preg_match('/character varying\((\d+)\)/', $xmlType, $xmlMatches);
                preg_match('/character varying\((\d+)\)/', $dbType, $dbMatches);
                
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
