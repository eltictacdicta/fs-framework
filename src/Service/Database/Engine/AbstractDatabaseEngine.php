<?php

namespace FSFramework\Service\Database\Engine;

use FSFramework\Service\Log\CoreLogManager;

/**
 * Clase base abstracta para los motores de base de datos
 */
abstract class AbstractDatabaseEngine implements DatabaseEngineInterface
{
    /**
     * Conexión a la base de datos
     */
    protected static $link = null;

    /**
     * Gestor de logs
     */
    protected static ?CoreLogManager $coreLog = null;

    /**
     * Contador de selects
     */
    protected static int $tSelects = 0;

    /**
     * Contador de transacciones
     */
    protected static int $tTransactions = 0;

    /**
     * Historial de consultas SQL
     */
    protected static array $sqlHistory = [];

    public function __construct()
    {
        if (!isset(self::$link)) {
            self::$tSelects = 0;
            self::$tTransactions = 0;
            self::$coreLog = new CoreLogManager();
            self::$sqlHistory = [];
        }
    }

    /**
     * Devuelve TRUE si se está conectado a la base de datos.
     */
    public function connected(): bool
    {
        return (bool) self::$link;
    }

    /**
     * Devuelve el historial SQL.
     */
    public function getHistory(): array
    {
        return self::$sqlHistory;
    }

    /**
     * Devuelve el nº de selects a la base de datos.
     */
    public function getSelects(): int
    {
        return self::$tSelects;
    }

    /**
     * Devuelve el nº de transacciones con la base de datos.
     */
    public function getTransactions(): int
    {
        return self::$tTransactions;
    }

    /**
     * Añade una consulta SQL al historial
     */
    protected function addToHistory(string $sql): void
    {
        if (defined('FS_DB_HISTORY') && FS_DB_HISTORY) {
            self::$sqlHistory[] = $sql;
        }
    }
}
