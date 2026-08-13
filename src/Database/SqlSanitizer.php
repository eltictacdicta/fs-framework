<?php

declare(strict_types=1);

namespace FSFramework\Database;

/**
 * Valida plantillas SQL y parámetros antes de ejecutarlos en los drivers legacy.
 *
 * Los valores dinámicos deben ir siempre en bind params (?), nunca concatenados en la plantilla.
 */
final class SqlSanitizer
{
    /**
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    public static function prepareForExecution(string $sql, array $params = []): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \InvalidArgumentException('SQL query cannot be empty.');
        }

        $boundParams = array_values($params);
        if ($boundParams !== []) {
            self::assertPlaceholderCountMatches($sql, count($boundParams));
        }

        return [$sql, $boundParams];
    }

    /**
     * Divide batches DDL generados por el framework (compare_columns, etc.).
     *
     * @return list<string>
     */
    public static function splitTrustedBatch(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            return [];
        }

        if (!str_contains($sql, ';')) {
            return [$sql];
        }

        $statements = preg_split(
            '/;(?=\s*(?:ALTER|CREATE|DROP|INSERT|UPDATE|DELETE|SET|SELECT|TRUNCATE|REPLACE)\b)/i',
            $sql,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($statements === false || $statements === []) {
            return [rtrim($sql, " \t\n\r\0\x0B;")];
        }

        $normalized = [];
        foreach ($statements as $statement) {
            $statement = trim($statement, " \t\n\r\0\x0B;");
            if ($statement !== '') {
                $normalized[] = $statement;
            }
        }

        return $normalized !== [] ? $normalized : [rtrim($sql, " \t\n\r\0\x0B;")];
    }

    private static function assertPlaceholderCountMatches(string $sql, int $paramCount): void
    {
        if (!preg_match_all('/(?<!\\\\)\?/', $sql, $matches)) {
            if ($paramCount > 0) {
                throw new \InvalidArgumentException('SQL bind parameters provided without placeholders.');
            }

            return;
        }

        if (count($matches[0]) !== $paramCount) {
            throw new \InvalidArgumentException('SQL placeholder count does not match bind parameters.');
        }
    }
}
