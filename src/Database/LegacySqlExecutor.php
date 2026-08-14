<?php

declare(strict_types=1);

namespace FSFramework\Database;

/**
 * Punto único de ejecución SQL para drivers legacy (mysqli / pgsql).
 *
 * Usa sentencias preparadas y bind parameters; evita query()/multi_query()/pg_query() directos.
 */
final class LegacySqlExecutor
{
    /**
     * @param \mysqli|object $link
     * @param-out int|null $affectedRows When provided, receives rows affected by the statement.
     */
    public static function executeMysqlWrite(object $link, string $sqlTemplate, array $params = [], ?int &$affectedRows = null): bool
    {
        $trackAffectedRows = func_num_args() >= 4;

        [$sqlTemplate, $params] = SqlSanitizer::prepareForExecution($sqlTemplate, $params);

        $statement = $link->prepare($sqlTemplate);
        if ($statement === false) {
            if ($trackAffectedRows) {
                $affectedRows = -1;
            }

            return false;
        }

        try {
            $executed = $params !== [] ? $statement->execute($params) : $statement->execute();
            if ($trackAffectedRows) {
                $affectedRows = $executed ? (int) $statement->affected_rows : -1;
            }

            return $executed;
        } finally {
            $statement->close();
        }
    }

    /**
     * @param \mysqli|object $link
     */
    public static function executeMysqlSelect(object $link, string $sqlTemplate, array $params = []): \mysqli_result|false
    {
        [$sqlTemplate, $params] = SqlSanitizer::prepareForExecution($sqlTemplate, $params);

        $statement = $link->prepare($sqlTemplate);
        if ($statement === false) {
            return false;
        }

        $executed = $params !== [] ? $statement->execute($params) : $statement->execute();
        if (!$executed) {
            $statement->close();

            return false;
        }

        $result = $statement->get_result();
        $statement->close();

        return $result;
    }

    /**
     * @param \mysqli|object $link
     */
    public static function executeMysqlBatch(object $link, string $sql): bool
    {
        foreach (SqlSanitizer::splitTrustedBatch($sql) as $statementSql) {
            $affectedRows = 0;
            if (!self::executeMysqlWrite($link, $statementSql, [], $affectedRows)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param resource $link
     * @return resource|false
     */
    public static function executePostgresQuery($link, string $sqlTemplate, array $params = [])
    {
        [$sqlTemplate, $params] = SqlSanitizer::prepareForExecution($sqlTemplate, $params);

        if ($params === [] && str_contains($sqlTemplate, ';')) {
            $lastResult = false;
            foreach (SqlSanitizer::splitTrustedBatch($sqlTemplate) as $statementSql) {
                $lastResult = @pg_query_params($link, $statementSql, []);
                if ($lastResult === false) {
                    return false;
                }
            }

            return $lastResult;
        }

        return @pg_query_params($link, $sqlTemplate, $params);
    }
}
