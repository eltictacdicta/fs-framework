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
     * @param \PgSql\Connection|string $link
     * @return \PgSql\Result|false
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

        // Only bound SQL has placeholders; a ? without params is literal text ('¿qué?').
        return @pg_query_params($link, $params === [] ? $sqlTemplate : self::toPostgresPlaceholders($sqlTemplate), $params);
    }

    /**
     * ? -> $1, $2... after the placeholder count was validated on the ? form
     * (validating the converted SQL found no ? and rejected every bound query).
     * Same placeholder rule as SqlSanitizer: a backslash-escaped \? is left alone.
     */
    public static function toPostgresPlaceholders(string $sql): string
    {
        $i = 0;

        return (string) preg_replace_callback('/(?<!\\\\)\?/', static function () use (&$i): string {
            return '$' . ++$i;
        }, $sql);
    }
}
