<?php

namespace FSFramework\Service\Database;

use FSFramework\Service\Database\Engine\DatabaseEngineInterface;
use FSFramework\Service\Database\Engine\MySQLEngine;
use FSFramework\Service\Database\Engine\PostgreSQLEngine;

/**
 * Clase genérica de acceso a la base de datos, ya sea MySQL o PostgreSQL.
 */
class DatabaseManager
{
    /**
     * Transacciones automáticas activadas si o no.
     */
    private static bool $autoTransactions = true;

    /**
     * Motor utilizado, MySQL o PostgreSQL
     */
    private static ?DatabaseEngineInterface $engine = null;

    /**
     * Última lista de tablas de la base de datos.
     */
    private static array|false $tableList = false;

    public function __construct()
    {
        if (!isset(self::$engine)) {
            if (strtolower(FS_DB_TYPE) == 'mysql') {
                self::$engine = new MySQLEngine();
            } else {
                self::$engine = new PostgreSQLEngine();
            }

            self::$autoTransactions = true;
            self::$tableList = false;
        }
    }

    /**
     * Inicia una transacción SQL.
     */
    public function beginTransaction(): bool
    {
        return self::$engine->beginTransaction();
    }

    /**
     * Realiza comprobaciones extra a la tabla.
     */
    public function checkTableAux(string $tableName): bool
    {
        return self::$engine->checkTableAux($tableName);
    }

    /**
     * Desconecta de la base de datos.
     */
    public function close(): bool
    {
        return self::$engine->close();
    }

    /**
     * Guarda los cambios de una transacción SQL.
     */
    public function commit(): bool
    {
        return self::$engine->commit();
    }

    /**
     * Compara los tipos de las columnas de una tabla con las definiciones XML.
     */
    public function compareColumns(string $tableName, array $xmlCols, array $dbCols): string
    {
        return self::$engine->compareColumns($tableName, $xmlCols, $dbCols);
    }

    /**
     * Compara las restricciones de una tabla con las definiciones XML.
     */
    public function compareConstraints(string $tableName, array $xmlCons, array $dbCons, bool $deleteOnly = false): string
    {
        return self::$engine->compareConstraints($tableName, $xmlCons, $dbCons, $deleteOnly);
    }

    /**
     * Conecta a la base de datos.
     */
    public function connect(): bool
    {
        return self::$engine->connect();
    }

    /**
     * Devuelve TRUE si se está conectado a la base de datos.
     */
    public function connected(): bool
    {
        return self::$engine->connected();
    }

    /**
     * Devuelve el estilo de fecha del motor de base de datos.
     */
    public function dateStyle(): string
    {
        return self::$engine->dateStyle();
    }

    /**
     * Escapa las comillas de la cadena de texto.
     */
    public function escapeString(string $str): string
    {
        return self::$engine->escapeString($str);
    }

    /**
     * Ejecuta sentencias SQL sobre la base de datos (inserts, updates o deletes).
     * Para hacer selects, mejor usar select() o selectLimit().
     * Por defecto se inicia una transacción, se ejecutan las consultas, y si todo
     * sale bien, se guarda, sino se deshace.
     * Se puede evitar este modo de transacción si se pone false
     * en el parametro transaction, o con la función setAutoTransactions(FALSE)
     */
    public function exec(string $sql, ?bool $transaction = null): bool
    {
        // Usamos self::$autoTransactions como valor por defecto para la función
        if (is_null($transaction)) {
            $transaction = self::$autoTransactions;
        }

        // Limpiamos la lista de tablas, ya que podría haber cambios al ejecutar este sql.
        self::$tableList = false;

        return self::$engine->exec($sql, $transaction);
    }

    /**
     * Devuelve la sentencia sql necesaria para crear una tabla con la estructura proporcionada.
     */
    public function generateTable(string $tableName, array $xmlCols, array $xmlCons): string
    {
        return self::$engine->generateTable($tableName, $xmlCols, $xmlCons);
    }

    /**
     * Devuelve el valor de autoTransactions, para saber si las transacciones
     * automáticas están activadas o no.
     */
    public function getAutoTransactions(): bool
    {
        return self::$autoTransactions;
    }

    /**
     * Devuelve un array con las columnas de una tabla dada.
     */
    public function getColumns(string $tableName): array
    {
        return self::$engine->getColumns($tableName);
    }

    /**
     * Devuelve una array con las restricciones de una tabla dada.
     */
    public function getConstraints(string $tableName, bool $extended = false): array
    {
        if ($extended) {
            return self::$engine->getConstraintsExtended($tableName);
        }

        return self::$engine->getConstraints($tableName);
    }

    /**
     * Devuelve el historial SQL.
     */
    public function getHistory(): array
    {
        return self::$engine->getHistory();
    }

    /**
     * Devuelve una array con los indices de una tabla dada.
     */
    public function getIndexes(string $tableName): array
    {
        return self::$engine->getIndexes($tableName);
    }

    /**
     * Devuelve un array con los bloqueos de la base de datos.
     */
    public function getLocks(): array
    {
        return self::$engine->getLocks();
    }

    /**
     * Devuelve el nº de selects a la base de datos.
     */
    public function getSelects(): int
    {
        return self::$engine->getSelects();
    }

    /**
     * Devuelve el nº de transacciones con la base de datos.
     */
    public function getTransactions(): int
    {
        return self::$engine->getTransactions();
    }

    /**
     * Devuleve el último ID asignado al hacer un INSERT en la base de datos.
     */
    public function lastval(): int
    {
        return self::$engine->lastval();
    }

    /**
     * Devuelve un array con los nombres de las tablas de la base de datos.
     */
    public function listTables(): array
    {
        if (self::$tableList === false) {
            self::$tableList = self::$engine->listTables();
        }

        return self::$tableList;
    }

    /**
     * Deshace los cambios de una transacción SQL.
     */
    public function rollback(): bool
    {
        return self::$engine->rollback();
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, y devuelve un array con los resultados,
     * o false en caso de fallo.
     */
    public function select(string $sql): array|false
    {
        return self::$engine->select($sql);
    }

    /**
     * Ejecuta una sentencia SQL de tipo select, pero con paginación,
     * y devuelve un array con los resultados o false en caso de fallo.
     * Limit es el número de elementos que quieres que devuelva.
     * Offset es el número de resultado desde el que quieres que empiece.
     */
    public function selectLimit(string $sql, int $limit = FS_ITEM_LIMIT, int $offset = 0): array|false
    {
        return self::$engine->selectLimit($sql, $limit, $offset);
    }

    /**
     * Activa/desactiva las transacciones automáticas en la función exec()
     */
    public function setAutoTransactions(bool $value): void
    {
        self::$autoTransactions = $value;
    }

    /**
     * Devuelve el SQL necesario para convertir la columna a entero.
     */
    public function sqlToInt(string $colName): string
    {
        return self::$engine->sqlToInt($colName);
    }

    /**
     * Devuelve TRUE si la tabla existe, FALSE en caso contrario.
     */
    public function tableExists(string $name, array|false $list = false): bool
    {
        $result = false;

        if ($list === false) {
            $list = $this->listTables();
        }

        foreach ($list as $table) {
            if ($table['name'] == $name) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Devuelve la versión del motor de base de datos.
     */
    public function version(): string
    {
        return self::$engine->version();
    }
}
