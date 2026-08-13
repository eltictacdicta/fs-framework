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
     */
    public static function executeMysqlWrite(object $link, string $sqlTemplate, array $params = []): bool
    {
        [$sqlTemplate, $params] = SqlSanitizer::prepareForExecution($sqlTemplate, $params);

        $statement = $link->prepare($sqlTemplate);
        if ($statement === false) {
            return false;
        }

        try {
            return $params !== [] ? $statement->execute($params) : $statement->execute();
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
            if (!self::executeMysqlWrite($link, $statementSql)) {
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
