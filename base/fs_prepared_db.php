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
 * Wrapper de base de datos con prepared statements
 * 
 * Proporciona una capa sobre fs_db2 que usa prepared statements
 * para mejor seguridad (prevención de SQL injection) y performance
 * (reutilización de statements compilados).
 * 
 * Uso:
 *   $db = new fs_prepared_db();
 *   
 *   // Select con parámetros
 *   $users = $db->query(
 *       "SELECT * FROM fs_users WHERE admin = ? AND enabled = ?",
 *       [true, true]
 *   );
 *   
 *   // Insert
 *   $db->execute(
 *       "INSERT INTO clientes (nombre, email) VALUES (?, ?)",
 *       [$nombre, $email]
 *   );
 *   
 *   // Con tipos explícitos
 *   $db->execute(
 *       "UPDATE productos SET stock = ? WHERE referencia = ?",
 *       [100, 'REF001'],
 *       'is'  // i=integer, s=string
 *   );
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_prepared_db
{
    /**
     * @var mysqli|null Conexión MySQL
     */
    private static ?mysqli $connection = null;

    /**
     * @var array Cache de prepared statements
     */
    private static array $stmtCache = [];

    /**
     * @var int Máximo de statements en cache
     */
    private static int $maxCacheSize = 100;

    /**
     * @var fs_db2|null Instancia legacy para fallback
     */
    private ?fs_db2 $legacyDb = null;

    /**
     * @var int Contador de queries
     */
    private static int $queryCount = 0;

    /**
     * @var float Tiempo total de queries
     */
    private static float $totalTime = 0.0;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->ensureConnection();
    }

    /**
     * Asegura que hay una conexión activa
     */
    private function ensureConnection(): void
    {
        if (self::$connection !== null && self::$connection->ping()) {
            return;
        }

        // Obtener conexión desde fs_db2 si es posible
        if ($this->legacyDb === null) {
            $this->legacyDb = new fs_db2();
        }

        if (!$this->legacyDb->connected()) {
            $this->legacyDb->connect();
        }

        // Para MySQL, intentar obtener la conexión directa
        if (strtolower(FS_DB_TYPE) === 'mysql') {
            self::$connection = new mysqli(
                FS_DB_HOST,
                FS_DB_USER,
                FS_DB_PASS,
                FS_DB_NAME,
                defined('FS_DB_PORT') ? (int) FS_DB_PORT : 3306
            );

            if (self::$connection->connect_error) {
                throw new RuntimeException('Error de conexión MySQL: ' . self::$connection->connect_error);
            }

            self::$connection->set_charset('utf8mb4');
        }
    }

    /**
     * Ejecuta una consulta SELECT con prepared statement
     * 
     * @param string $sql SQL con placeholders (?)
     * @param array $params Parámetros
     * @param string|null $types Tipos de parámetros (i=int, d=double, s=string, b=blob)
     * @return array Resultados
     */
    public function query(string $sql, array $params = [], ?string $types = null): array
    {
        $start = microtime(true);

        try {
            // Si no hay parámetros o no es MySQL, usar legacy
            if (empty($params) || self::$connection === null) {
                return $this->queryLegacy($sql, $params);
            }

            $stmt = $this->getStatement($sql);
            
            if ($stmt === false) {
                return $this->queryLegacy($sql, $params);
            }

            // Determinar tipos si no se proporcionan
            if ($types === null) {
                $types = $this->detectTypes($params);
            }

            // Bind de parámetros
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            if ($result === false) {
                return [];
            }

            $data = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();

            return $data;

        } finally {
            self::$queryCount++;
            self::$totalTime += microtime(true) - $start;
        }
    }

    /**
     * Ejecuta una consulta INSERT/UPDATE/DELETE con prepared statement
     * 
     * @param string $sql SQL con placeholders (?)
     * @param array $params Parámetros
     * @param string|null $types Tipos de parámetros
     * @return bool Éxito
     */
    public function execute(string $sql, array $params = [], ?string $types = null): bool
    {
        $start = microtime(true);

        try {
            // Si no hay parámetros o no es MySQL, usar legacy
            if (empty($params) || self::$connection === null) {
                return $this->executeLegacy($sql, $params);
            }

            $stmt = $this->getStatement($sql);
            
            if ($stmt === false) {
                return $this->executeLegacy($sql, $params);
            }

            // Determinar tipos si no se proporcionan
            if ($types === null) {
                $types = $this->detectTypes($params);
            }

            // Bind de parámetros
            $stmt->bind_param($types, ...$params);

            return $stmt->execute();

        } finally {
            self::$queryCount++;
            self::$totalTime += microtime(true) - $start;
        }
    }

    /**
     * Obtiene el último ID insertado
     * 
     * @return int|string
     */
    public function lastInsertId(): int|string
    {
        if (self::$connection !== null) {
            return self::$connection->insert_id;
        }

        return $this->legacyDb->lastval();
    }

    /**
     * Obtiene el número de filas afectadas
     * 
     * @return int
     */
    public function affectedRows(): int
    {
        if (self::$connection !== null) {
            return self::$connection->affected_rows;
        }

        return 0;
    }

    /**
     * Inicia una transacción
     * 
     * @return bool
     */
    public function beginTransaction(): bool
    {
        if (self::$connection !== null) {
            return self::$connection->begin_transaction();
        }

        return $this->legacyDb->begin_transaction();
    }

    /**
     * Confirma una transacción
     * 
     * @return bool
     */
    public function commit(): bool
    {
        if (self::$connection !== null) {
            return self::$connection->commit();
        }

        return $this->legacyDb->commit();
    }

    /**
     * Revierte una transacción
     * 
     * @return bool
     */
    public function rollback(): bool
    {
        if (self::$connection !== null) {
            return self::$connection->rollback();
        }

        return $this->legacyDb->rollback();
    }

    /**
     * Escapa un valor para uso en SQL
     * 
     * @param string $value Valor a escapar
     * @return string
     */
    public function escape(string $value): string
    {
        if (self::$connection !== null) {
            return self::$connection->real_escape_string($value);
        }

        return $this->legacyDb->escape_string($value);
    }

    /**
     * Obtiene o crea un prepared statement desde cache
     * 
     * @param string $sql SQL
     * @return mysqli_stmt|false
     */
    private function getStatement(string $sql): mysqli_stmt|false
    {
        $key = md5($sql);

        // Buscar en cache
        if (isset(self::$stmtCache[$key])) {
            return self::$stmtCache[$key];
        }

        // Limpiar cache si está lleno
        if (count(self::$stmtCache) >= self::$maxCacheSize) {
            $this->clearOldestStatements(10);
        }

        // Crear nuevo statement
        $stmt = self::$connection->prepare($sql);
        
        if ($stmt !== false) {
            self::$stmtCache[$key] = $stmt;
        }

        return $stmt;
    }

    /**
     * Detecta los tipos de los parámetros
     * 
     * @param array $params Parámetros
     * @return string Tipos (i, d, s, b)
     */
    private function detectTypes(array $params): string
    {
        $types = '';
        
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_bool($param)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * Ejecuta query usando el sistema legacy
     * 
     * @param string $sql SQL
     * @param array $params Parámetros (se interpolan)
     * @return array
     */
    private function queryLegacy(string $sql, array $params): array
    {
        $sql = $this->interpolateParams($sql, $params);
        $result = $this->legacyDb->select($sql);
        return is_array($result) ? $result : [];
    }

    /**
     * Ejecuta statement usando el sistema legacy
     * 
     * @param string $sql SQL
     * @param array $params Parámetros (se interpolan)
     * @return bool
     */
    private function executeLegacy(string $sql, array $params): bool
    {
        $sql = $this->interpolateParams($sql, $params);
        return (bool) $this->legacyDb->exec($sql);
    }

    /**
     * Interpola parámetros en SQL (para fallback legacy)
     * 
     * @param string $sql SQL con ?
     * @param array $params Parámetros
     * @return string SQL interpolado
     */
    private function interpolateParams(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        $parts = explode('?', $sql);
        $result = '';

        foreach ($parts as $i => $part) {
            $result .= $part;
            if (isset($params[$i])) {
                $value = $params[$i];
                if ($value === null) {
                    $result .= 'NULL';
                } elseif (is_bool($value)) {
                    $result .= $value ? '1' : '0';
                } elseif (is_int($value) || is_float($value)) {
                    $result .= $value;
                } else {
                    $result .= "'" . $this->escape((string) $value) . "'";
                }
            }
        }

        return $result;
    }

    /**
     * Limpia los statements más antiguos del cache
     * 
     * @param int $count Cantidad a eliminar
     */
    private function clearOldestStatements(int $count): void
    {
        $keys = array_keys(self::$stmtCache);
        $toRemove = array_slice($keys, 0, $count);

        foreach ($toRemove as $key) {
            if (isset(self::$stmtCache[$key])) {
                self::$stmtCache[$key]->close();
                unset(self::$stmtCache[$key]);
            }
        }
    }

    /**
     * Obtiene estadísticas de queries
     * 
     * @return array
     */
    public static function getStats(): array
    {
        return [
            'query_count' => self::$queryCount,
            'total_time' => round(self::$totalTime, 4),
            'avg_time' => self::$queryCount > 0 
                ? round(self::$totalTime / self::$queryCount, 4) 
                : 0,
            'cached_statements' => count(self::$stmtCache),
        ];
    }

    /**
     * Limpia todo el cache de statements
     */
    public static function clearCache(): void
    {
        foreach (self::$stmtCache as $stmt) {
            $stmt->close();
        }
        self::$stmtCache = [];
    }

    /**
     * Cierra la conexión
     */
    public static function close(): void
    {
        self::clearCache();
        
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        // No cerrar la conexión aquí para permitir reutilización
    }
}
