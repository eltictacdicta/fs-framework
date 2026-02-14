<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Gestiona los esquemas de tablas desde archivos XML
 * 
 * Compatible con el formato de FacturaScripts y mejora la creación
 * de tablas con soporte para MySQL y PostgreSQL.
 * 
 * Uso:
 *   // Crear tabla desde XML
 *   fs_schema::createFromXml('model/table/clientes.xml');
 *   
 *   // Verificar si tabla existe
 *   if (fs_schema::tableExists('clientes')) { ... }
 *   
 *   // Instalar tablas del core
 *   fs_schema::installCoreTables();
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_schema
{
    private const SQL_LINE_SEPARATOR = ",\n  ";

    /**
     * Mapeo de tipos PostgreSQL a MySQL
     * @var array
     */
    private static $typeMapping = [
        'character varying' => 'VARCHAR',
        'character' => 'CHAR',
        'text' => 'TEXT',
        'integer' => 'INT',
        'smallint' => 'SMALLINT',
        'bigint' => 'BIGINT',
        'boolean' => 'TINYINT(1)',
        'double precision' => 'DOUBLE',
        'real' => 'FLOAT',
        'numeric' => 'DECIMAL',
        'date' => 'DATE',
        'time' => 'TIME',
        'timestamp' => 'TIMESTAMP',
        'datetime' => 'DATETIME',
        'bytea' => 'BLOB',
        'serial' => 'INT AUTO_INCREMENT',
    ];

    /**
     * @var fs_db2|null
     */
    private static $db = null;

    /**
     * Obtiene la instancia de base de datos
     * 
     * @return fs_db2
     */
    private static function getDb()
    {
        if (self::$db === null) {
            self::$db = new fs_db2();
        }
        return self::$db;
    }

    /**
     * Verifica si estamos usando MySQL
     * 
     * @return bool
     */
    private static function isMySQL()
    {
        return defined('FS_DB_TYPE') && strtolower(FS_DB_TYPE) === 'mysql';
    }

    /**
     * Crea una tabla desde un archivo XML
     * 
     * @param string $xmlFile Ruta al archivo XML
     * @return bool
     * @throws Exception Si el archivo no existe o hay error de parseo
     */
    public static function createFromXml($xmlFile)
    {
        if (!file_exists($xmlFile)) {
            throw new Exception("Archivo XML no encontrado: {$xmlFile}");
        }

        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            throw new Exception("Error al parsear XML: {$xmlFile}");
        }

        $tableName = pathinfo($xmlFile, PATHINFO_FILENAME);
        
        return self::createTable($tableName, $xml);
    }

    /**
     * Crea una tabla desde un objeto SimpleXML
     * 
     * @param string $tableName Nombre de la tabla
     * @param SimpleXMLElement $xml Definición XML
     * @return bool
     */
    public static function createTable($tableName, $xml)
    {
        $isMySQL = self::isMySQL();
        $columns = self::collectColumns($xml, $isMySQL);
        $constraints = self::collectConstraints($xml, $isMySQL);

        if (empty($columns)) {
            return false;
        }
        $sql = self::buildCreateTableSql($tableName, $columns, $constraints, $isMySQL);

        try {
            $db = self::getDb();
            return $db->exec($sql);
        } catch (Exception $e) {
            $code = (string) $e->getCode();
            if ($code === '1050' || strtoupper($code) === '42P07') {
                return true;
            }
            throw $e;
        }
    }

    private static function collectColumns($xml, $isMySQL)
    {
        $columns = [];
        if (!isset($xml->columna)) {
            return $columns;
        }

        foreach ($xml->columna as $col) {
            $colDef = self::parseColumn($col, $isMySQL);
            if ($colDef) {
                $columns[] = $colDef;
            }
        }

        return $columns;
    }

    /**
     * Recolecta restricciones SQL desde XML.
     *
     * @param SimpleXMLElement $xml Definición XML
     * @param bool $isMySQL Si es MySQL
     * @param bool $validateFks Si true, valida que la tabla referenciada exista (requiere conexión DB)
     * @return array
     */
    private static function collectConstraints($xml, $isMySQL, bool $validateFks = true)
    {
        $constraints = [];
        if (!isset($xml->restriccion)) {
            return $constraints;
        }

        $db = null;
        if ($validateFks) {
            $db = self::getDb();
        }

        foreach ($xml->restriccion as $rest) {
            $name = (string) $rest->nombre;
            $query = (string) $rest->consulta;
            if (stripos($query, 'PRIMARY KEY') !== false || stripos($query, 'UNIQUE') !== false) {
                $constraints[] = $query;
                continue;
            }

            if (stripos($query, 'FOREIGN KEY') !== false) {
                self::addForeignKeyConstraint($query, $name, $db, $constraints, $isMySQL, $validateFks);
            }
        }

        return $constraints;
    }

    private static function buildCreateTableSql($tableName, array $columns, array $constraints, $isMySQL)
    {
        $quote = $isMySQL ? '`' : '"';
        $sql = "CREATE TABLE IF NOT EXISTS {$quote}{$tableName}{$quote} (\n";
        $sql .= "  " . implode(self::SQL_LINE_SEPARATOR, $columns);
        if (!empty($constraints)) {
            $sql .= self::SQL_LINE_SEPARATOR . implode(self::SQL_LINE_SEPARATOR, $constraints);
        }
        $sql .= "\n)";

        if ($isMySQL) {
            $sql .= " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        return $sql;
    }

    /**
     * Parsea una columna XML y devuelve la definición SQL
     * 
     * @param SimpleXMLElement $col Elemento columna
     * @param bool $isMySQL Si es MySQL
     * @return string
     */
    private static function parseColumn($col, $isMySQL)
    {
        $name = (string) $col->nombre;
        $type = (string) $col->tipo;
        $nullable = !isset($col->nulo) || strtoupper((string) $col->nulo) !== 'NO';
        $default = isset($col->defecto) ? (string) $col->defecto : null;

        // Convertir tipo de datos
        $sqlType = self::convertType($type, $isMySQL);

        // Construir definición
        $quote = $isMySQL ? '`' : '"';
        $def = "{$quote}{$name}{$quote} {$sqlType}";
        
        if (!$nullable) {
            $def .= " NOT NULL";
        }
        
        if ($default !== null && $default !== '') {
            if ($default === 'true' || $default === 'false') {
                $def .= " DEFAULT " . ($default === 'true' ? '1' : '0');
            } elseif (is_numeric($default)) {
                $def .= " DEFAULT {$default}";
            } elseif (strtoupper($default) === 'CURRENT_TIMESTAMP' || strtoupper($default) === 'NOW()') {
                $def .= " DEFAULT CURRENT_TIMESTAMP";
            } elseif (strtoupper($default) === 'NULL') {
                $def .= " DEFAULT NULL";
            } else {
                $def .= " DEFAULT '{$default}'";
            }
        }

        return $def;
    }

    /**
     * Convierte un tipo de datos PostgreSQL a MySQL
     * 
     * @param string $type Tipo original
     * @param bool $isMySQL Si es MySQL
     * @return string
     */
    private static function convertType($type, $isMySQL)
    {
        if (!$isMySQL) {
            return $type;
        }

        // Extraer tipo base y longitud
        $matches = [];
        if (preg_match('/^([a-z\s]+)(?:\((\d+(?:,\d+)?)\))?$/i', trim($type), $matches)) {
            $baseType = strtolower(trim($matches[1]));
            $length = isset($matches[2]) ? $matches[2] : null;

            // Buscar en el mapeo
            foreach (self::$typeMapping as $pgType => $mysqlType) {
                if ($baseType === $pgType || strpos($baseType, $pgType) === 0) {
                    if ($length && strpos($mysqlType, '(') === false) {
                        return "{$mysqlType}({$length})";
                    }
                    return $mysqlType;
                }
            }
        }

        // Si no se encuentra, devolver el tipo original en mayúsculas
        return strtoupper($type);
    }

    /**
     * Helper to validate and add a foreign key constraint.
     * If the REFERENCES pattern matches and the referenced table exists, the
     * constraint is pushed into $constraints. Otherwise a warning is logged
     * with the constraint name and raw query so issues are visible.
     *
     * @param string $query
     * @param string $name
     * @param fs_db2 $db
     * @param array &$constraints
     * @return void
     */
    private static function addForeignKeyConstraint($query, $name, $db, array & $constraints, bool $isMySQL, bool $validateFks)
    {
        $quote = $isMySQL ? '`' : '"';
        $constraintSql = "CONSTRAINT {$quote}{$name}{$quote} " . $query;
        if (!$validateFks) {
            $constraints[] = $constraintSql;
            return;
        }

        $matches = [];
        // Accept unquoted, backticked or double-quoted identifiers, allow schema-qualified names
        if (preg_match('/REFERENCES\s+(?:`([^`]+)`|"([^"]+)"|([A-Za-z0-9_\.]+))/i', $query, $matches)) {
            $refTable = $matches[1] ?: $matches[2] ?: $matches[3] ?: '';
            // If schema-qualified (schema.table), take the last segment
            if (strpos($refTable, '.') !== false) {
                $parts = explode('.', $refTable);
                $refTable = end($parts);
            }
            $refTable = trim($refTable, '"`');

            if ($refTable === '') {
                error_log("Advertencia: Foreign key '{$name}' omitida - nombre de tabla referenciada vacío. Query: {$query}");
                return;
            }

            if ($db && $db->table_exists($refTable)) {
                $constraints[] = $constraintSql;
            } else {
                error_log("Advertencia: Foreign key '{$name}' omitida - tabla referenciada '{$refTable}' no existe. Query: {$query}");
            }
        } else {
            error_log("Advertencia: Foreign key '{$name}' omitida - patrón REFERENCES no coincide. Query: {$query}");
        }
    }

    /**
     * Verifica si una tabla existe
     * 
     * @param string $tableName Nombre de la tabla
     * @return bool
     */
    public static function tableExists($tableName)
    {
        try {
            $db = self::getDb();
            return $db->table_exists($tableName);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Elimina una tabla
     * 
     * @param string $tableName Nombre de la tabla
     * @return bool
     */
    public static function dropTable($tableName)
    {
        try {
            $db = self::getDb();
            $quote = self::isMySQL() ? '`' : '"';
            return $db->exec("DROP TABLE IF EXISTS {$quote}{$tableName}{$quote}");
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtiene las columnas de una tabla
     * 
     * @param string $tableName Nombre de la tabla
     * @return array
     */
    public static function getColumns($tableName)
    {
        $db = self::getDb();
        return $db->get_columns($tableName);
    }

    /**
     * Obtiene las restricciones de una tabla
     * 
     * @param string $tableName Nombre de la tabla
     * @return array
     */
    public static function getConstraints($tableName)
    {
        $db = self::getDb();
        return $db->get_constraints($tableName);
    }

    /**
     * Compara y actualiza una tabla con su definición XML
     * 
     * @param string $tableName Nombre de la tabla
     * @param string $xmlFile Ruta al archivo XML
     * @return array Cambios realizados
     */
    public static function syncTable($tableName, $xmlFile)
    {
        $changes = [];
        
        if (!file_exists($xmlFile)) {
            return ['error' => "Archivo XML no encontrado: {$xmlFile}"];
        }

        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            return ['error' => "Error al parsear XML: {$xmlFile}"];
        }

        // Si la tabla no existe, crearla
        if (!self::tableExists($tableName)) {
            if (self::createTable($tableName, $xml)) {
                $changes[] = "Tabla {$tableName} creada";
            }
            return $changes;
        }

        $db = self::getDb();
        $dbColumns = $db->get_columns($tableName);
        self::syncColumns($db, $tableName, $xml, $dbColumns, $changes);
        self::syncConstraints($db, $tableName, $xml, $changes);

        return $changes;
    }

    private static function syncColumns($db, $tableName, $xml, $dbColumns, array &$changes)
    {
        $xmlColumns = [];
        if (isset($xml->columna)) {
            foreach ($xml->columna as $col) {
                $xmlColumns[] = [
                    'nombre' => (string) $col->nombre,
                    'tipo' => (string) $col->tipo,
                    'nulo' => isset($col->nulo) ? (string) $col->nulo : 'YES',
                    'defecto' => isset($col->defecto) ? (string) $col->defecto : null,
                ];
            }
        }

        $sql = $db->compare_columns($tableName, $xmlColumns, $dbColumns);
        if (!empty($sql) && $db->exec($sql)) {
            $changes[] = "Columnas actualizadas en {$tableName}";
        }
    }

    private static function syncConstraints($db, $tableName, $xml, array &$changes)
    {
        if (!isset($xml->restriccion)) {
            return;
        }

        $dbConstraints = $db->get_constraints($tableName);
        $xmlConstraints = [];
        foreach ($xml->restriccion as $rest) {
            $xmlConstraints[] = [
                'nombre' => (string) $rest->nombre,
                'consulta' => (string) $rest->consulta,
            ];
        }

        $sql = $db->compare_constraints($tableName, $xmlConstraints, $dbConstraints);
        if (!empty($sql) && $db->exec($sql)) {
            $changes[] = "Restricciones actualizadas en {$tableName}";
        }
    }

    /**
     * Instala todas las tablas del sistema desde los archivos XML
     * 
     * @param string|null $tableDir Directorio de tablas (opcional)
     * @return array Resultados de instalación
     */
    public static function installCoreTables($tableDir = null)
    {
        $results = [];
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        
        if ($tableDir === null) {
            $tableDir = $folder . '/model/table';
        }
        
        if (!is_dir($tableDir)) {
            return ['error' => 'Directorio de tablas no encontrado: ' . $tableDir];
        }

        // Orden de instalación (por dependencias)
        $order = [
            'fs_pages.xml',
            'fs_users.xml',
            'fs_roles.xml',
            'fs_access.xml',
            'fs_roles_access.xml',
            'fs_roles_users.xml',
            'fs_vars.xml',
        ];

        // Primero instalar en orden
        foreach ($order as $file) {
            $path = $tableDir . '/' . $file;
            if (file_exists($path)) {
                try {
                    self::createFromXml($path);
                    $results[$file] = 'OK';
                } catch (Exception $e) {
                    $results[$file] = 'ERROR: ' . $e->getMessage();
                }
            }
        }

        // Luego instalar el resto
        foreach (glob($tableDir . '/*.xml') as $file) {
            $filename = basename($file);
            if (!isset($results[$filename])) {
                try {
                    self::createFromXml($file);
                    $results[$filename] = 'OK';
                } catch (Exception $e) {
                    $results[$filename] = 'ERROR: ' . $e->getMessage();
                }
            }
        }

        return $results;
    }

    /**
     * Genera SQL CREATE TABLE desde XML (sin ejecutar).
     *
     * Cuando $validateFks es true se puede requerir acceso a base de datos
     * para validar tablas referenciadas por claves foráneas.
     *
     * @param string $xmlFile Ruta al archivo XML
     * @param bool $validateFks Si true valida FKs contra DB; si false no accede a DB para validar FKs
     * @return string SQL generado
     */
    public static function generateSql($xmlFile, bool $validateFks = true)
    {
        if (!file_exists($xmlFile)) {
            throw new Exception("Archivo XML no encontrado: {$xmlFile}");
        }

        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            throw new Exception("Error al parsear XML: {$xmlFile}");
        }

        $tableName = pathinfo($xmlFile, PATHINFO_FILENAME);
        $isMySQL = self::isMySQL();
        $columns = self::collectColumns($xml, $isMySQL);
        $constraints = self::collectConstraints($xml, $isMySQL, $validateFks);

        return self::buildCreateTableSql($tableName, $columns, $constraints, $isMySQL);
    }

    /**
     * Añade un tipo al mapeo
     * 
     * @param string $pgType Tipo PostgreSQL
     * @param string $mysqlType Tipo MySQL equivalente
     * @return void
     */
    public static function addTypeMapping($pgType, $mysqlType)
    {
        self::$typeMapping[$pgType] = $mysqlType;
    }
}
