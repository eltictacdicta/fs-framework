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

namespace FSFramework\Translation;

use FSFramework\Translation\Store\TranslationStoreInterface;

/**
 * Content-translation resolution engine (design D3, D9).
 *
 * `read()` returns the first available non-null value walking, in order:
 * the exact caller locale, the language prefix (only when the store opted in,
 * through `$store->candidates()`), the configured default code, the caller
 * `base`, and finally `''`. It is strictly read-only: it never calls
 * `set()`, never materializes a fallback and never consults Request/session or
 * any request-scoped locale — the locale is always caller-supplied (CI-06,
 * CI-07).
 */
final class ContentTranslator
{
    private LanguageRegistry $registry;

    public function __construct(?LanguageRegistry $registry = null)
    {
        $this->registry = $registry ?? new LanguageRegistry();
    }

    /**
     * Ordered resolution: exact caller locale -> language prefix (when the store
     * opted in) -> configured default -> caller base -> ''.
     */
    public function read(
        TranslationStoreInterface $store,
        string $field,
        ?string $locale,
        string $base = ''
    ): string {
        if ($locale !== null && $locale !== '') {
            foreach ($store->candidates($locale) as $candidate) {
                $value = $store->get($field, $candidate);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $default = $this->registry->defaultCode();
        if ($default !== '') {
            $value = $store->get($field, $default);
            if ($value !== null) {
                return $value;
            }
        }

        if ($base !== '') {
            return $base;
        }

        return '';
    }

    /**
     * The configured default code, exposed for consumers that need the same
     * resolution the read chain uses.
     */
    public function defaultCode(): string
    {
        return $this->registry->defaultCode();
    }
}
