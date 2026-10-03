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
 * Read/write contract for a translatable field (design D3).
 *
 * `candidates()` is the store's declared, ordered reduction policy and the only
 * boundary where locale normalization happens. `get`/`set` are always exact for
 * the code they receive, so no read or construction rewrites a stored code
 * (CI-08, CI-10).
 */
interface TranslationStoreInterface
{
    /**
     * Exact stored value for a field + locale code; null when absent.
     */
    public function get(string $field, string $locale): ?string;

    /**
     * Write a value; null or '' clears that code's entry.
     */
    public function set(string $field, string $locale, ?string $value): bool;

    /**
     * Ordered, non-destructive candidate codes for a caller locale.
     *
     * @return list<string>
     */
    public function candidates(string $locale): array;
}

/**
 * Shared, mechanical candidate reduction for the concrete stores.
 *
 * It never rewrites the caller locale or a stored code: it only appends the
 * two-letter prefix when the store opted in and the prefix is not already the
 * whole locale. Alias mapping (for example the plugin-owned `en_US` alias) is
 * deliberately out of scope (CI-09, CI-10, CI-11).
 *
 * @internal
 *
 * @return list<string>
 */
function reduce_candidates(string $locale, bool $prefixFallback): array
{
    if ($locale === '') {
        return [];
    }

    $codes = [$locale];
    if (!$prefixFallback) {
        return $codes;
    }

    $prefix = substr($locale, 0, 2);
    if ($prefix !== '' && $prefix !== $locale) {
        $codes[] = $prefix;
    }

    return $codes;
}
