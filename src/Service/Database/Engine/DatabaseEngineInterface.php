<?php

namespace FSFramework\Service\Database\Engine;

/**
 * Interfaz para los motores de base de datos
 */
interface DatabaseEngineInterface
{
    /**
     * Inicia una transacción SQL.
     */
    public function beginTransaction(): bool;

    /**
     * Realiza comprobaciones extra a la tabla.
     */
    public function checkTableAux(string $tableName): bool;

    /**
     * Desconecta de la base de datos.
     */
    public function close(): bool;

    /**
     * Guarda los cambios de una transacción SQL.
     */
    public function commit(): bool;

    /**
     * Compara los tipos de las columnas de una tabla con las definiciones XML.
     */
    public function compareColumns(string $tableName, array $xmlCols, array $dbCols): string;

    /**
     * Compara las restricciones de una tabla con las definiciones XML.
     */
    public function compareConstraints(string $tableName, array $xmlCons, array $dbCons, bool $deleteOnly = false): string;

    /**
     * Conecta a la base de datos.
     */
    public function connect(): bool;

    /**
     * Devuelve TRUE si se está conectado a la base de datos.
     */
    public function connected(): bool;

    /**
     * Devuelve el estilo de fecha del motor de base de datos.
     */
    public function dateStyle(): string;

    /**
     * Escapa las comillas de la cadena de texto.
     */
    public function escapeString(string $str): string;

    /**
     * Ejecuta sentencias SQL sobre la base de datos (inserts, updates o deletes).
     */
    public function exec(string $sql, bool $transaction = true): bool;

    /**
     * Devuelve la sentencia sql necesaria para crear una tabla con la estructura proporcionada.
     */
    public function generateTable(string $tableName, array $xmlCols, array $xmlCons): string;

    /**
     * Devuelve un array con las columnas de una tabla dada.
     */
    public function getColumns(string $tableName): array;

    /**
     * Devuelve una array con las restricciones de una tabla dada.
     */
    public function getConstraints(string $tableName): array;

    /**
     * Devuelve una array con las restricciones extendidas de una tabla dada.
     */
    public function getConstraintsExtended(string $tableName): array;

    /**
     * Devuelve el historial SQL.
     */
    public function getHistory(): array;

    /**
     * Devuelve una array con los indices de una tabla dada.
     */
    public function getIndexes(string $tableName): array;

    /**
     * Devuelve un array con los bloqueos de la base de datos.
     */
    public function getLocks(): array;

    /**
     * Devuelve el nº de selects a la base de datos.
     */
    public function getSelects(): int;

    /**
     * Devuelve el nº de transacciones con la base de datos.
     */
    public function getTransactions(): int;

    /**
     * Devuleve el último ID asignado al hacer un INSERT en la base de datos.
     */
    public function lastval(): int;

    /**
     * Devuelve un array con los nombres de las tablas de la base de datos.
     */
    public function listTables(): array;

    /**
     * Deshace los cambios de una transacción SQL.
     */
    public function rollback(): bool;

    /**
     * Ejecuta una sentencia SQL de tipo select, y devuelve un array con los resultados,
     * o false en caso de fallo.
     */
    public function select(string $sql): array|false;

    /**
     * Ejecuta una sentencia SQL de tipo select, pero con paginación,
     * y devuelve un array con los resultados o false en caso de fallo.
     */
    public function selectLimit(string $sql, int $limit = FS_ITEM_LIMIT, int $offset = 0): array|false;

    /**
     * Devuelve el SQL necesario para convertir la columna a entero.
     */
    public function sqlToInt(string $colName): string;

    /**
     * Devuelve la versión del motor de base de datos.
     */
    public function version(): string;
}
