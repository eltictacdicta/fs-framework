<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FSFramework\Database;

/**
 * Resuelve el charset/collation opcionales declarados en el XML de una tabla
 * (`<charset>` / `<collation>` dentro de `<tabla>`) y construye la cláusula
 * MySQL correspondiente.
 *
 * Los valores se validan con el patrón identificador `^[a-z0-9_]+$`; un valor
 * inválido se descarta (se registra en el log) y el llamador cae al default de
 * la base de datos, que es el comportamiento histórico. Cuando no se declara
 * nada, la cláusula es byte a byte la de siempre.
 */
final class TableCharsetOptions
{
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';

    /**
     * Normaliza el par declarado. Cada miembro se valida de forma independiente:
     * los válidos se conservan en minúsculas y los inválidos/vacíos se descartan.
     *
     * @return array{charset: ?string, collation: ?string}
     */
    public static function normalize(?string $charset, ?string $collation): array
    {
        return [
            'charset' => self::sanitize($charset, 'charset'),
            'collation' => self::sanitize($collation, 'collation'),
        ];
    }

    /**
     * @param array{charset?: ?string, collation?: ?string} $options
     * @return array{charset: ?string, collation: ?string}
     */
    public static function fromArray(array $options): array
    {
        return self::normalize($options['charset'] ?? null, $options['collation'] ?? null);
    }

    /**
     * Construye el sufijo MySQL ENGINE/CHARSET/COLLATE.
     *
     * Cuando se declara un charset, se usa; si además se declara el collation,
     * se emite. Un collation sin charset no es fiable (pertenece a otro charset),
     * así que en ese caso se usa el par por defecto de la base de datos. Cuando
     * no se declara charset, el resultado es exactamente el default de la BD.
     *
     * @param array{charset?: ?string, collation?: ?string} $options salida de normalize()/fromArray()
     */
    public static function mysqlClause(array $options, string $dbCharset, string $dbCollation): string
    {
        $charset = $options['charset'] ?? null;
        $collation = $options['collation'] ?? null;

        if ($charset === null) {
            // Un collation aislado no se puede aplicar de forma segura sobre el
            // charset por defecto de la BD; se mantiene el default intacto.
            return 'ENGINE=InnoDB DEFAULT CHARSET=' . $dbCharset . ' COLLATE=' . $dbCollation;
        }

        $clause = 'ENGINE=InnoDB DEFAULT CHARSET=' . $charset;
        if ($collation !== null) {
            // Omitir COLLATE cuando no se declara deja que MySQL aplique el
            // collation por defecto del charset declarado, evitando emitir un
            // COLLATE de otro charset (error 1253).
            $clause .= ' COLLATE=' . $collation;
        }

        return $clause;
    }

    private static function sanitize(?string $value, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        if (!preg_match(self::IDENTIFIER_REGEX, $value)) {
            error_log("Advertencia: {$kind} inválido declarado en el XML de tabla, se ignora: {$value}");

            return null;
        }

        return $value;
    }
}
