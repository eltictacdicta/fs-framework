<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
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

namespace FSFramework\Translation\Store;

/**
 * Locale code => existing nullable column, with no schema change (design D3,
 * D4, D5).
 *
 * Reads and writes go through caller-supplied closures, so the store cannot
 * issue DDL or add a column. `candidates()` performs only the mechanical
 * `substr(locale, 0, 2)` suffix reduction; alias mapping such as
 * `en_US -> en_GB` stays entirely plugin-side (CI-11).
 */
final class FixedColumnTranslationStore implements TranslationStoreInterface
{
    /** @var array<string, string> locale code => column */
    private array $localeColumnMap;

    private \Closure $reader;

    private \Closure $writer;

    /**
     * @param array<string, string>                     $localeColumnMap e.g. ['es' => 'subtitle_es']
     * @param \Closure(string): ?string                 $reader
     * @param \Closure(string, ?string): bool           $writer
     */
    public function __construct(
        array $localeColumnMap,
        \Closure $reader,
        \Closure $writer,
        private bool $prefixFallback = true
    ) {
        $this->localeColumnMap = $localeColumnMap;
        $this->reader = $reader;
        $this->writer = $writer;
    }

    public function get(string $field, string $locale): ?string
    {
        $column = $this->localeColumnMap[$locale] ?? null;
        if ($column === null) {
            return null;
        }

        $value = ($this->reader)($column);

        return $value === null ? null : (string) $value;
    }

    public function set(string $field, string $locale, ?string $value): bool
    {
        $column = $this->localeColumnMap[$locale] ?? null;
        if ($column === null) {
            return false;
        }

        return (bool) ($this->writer)($column, $value);
    }

    public function candidates(string $locale): array
    {
        return reduce_candidates($locale, $this->prefixFallback);
    }
}
