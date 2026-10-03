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

/**
 * Service facade over the core `idioma` registry (design D2).
 *
 * The model remains the single source of truth for the durable lifecycle
 * invariants (CI-01…CI-05); this facade only exposes them to consumers through
 * a stable service id. It holds no cached state and never purges plugin
 * translation rows, which is the structural boundary of CI-15.
 */
final class LanguageRegistry
{
    private \idioma $model;

    /**
     * @param \idioma|null $model optional seam; defaults to the core model
     */
    public function __construct(?\idioma $model = null)
    {
        $this->model = $model ?? new \idioma();
    }

    /**
     * Total, deterministic default code (CI-01), delegated to the model.
     */
    public function defaultCode(): string
    {
        return $this->model->defaultCode();
    }

    /**
     * Active language codes ascending by `codidioma` (CI-02). Sorted in PHP so
     * the listing is deterministic regardless of the storage ORDER BY.
     *
     * @return list<string>
     */
    public function activeCodes(): array
    {
        $codes = [];
        foreach ($this->model->all_activos() as $row) {
            $codes[] = (string) $row->codidioma;
        }

        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * @return list<\idioma>
     */
    public function all(): array
    {
        return $this->model->all();
    }

    public function get(string $codidioma): ?\idioma
    {
        return $this->model->get($codidioma) ?: null;
    }

    public function defaultRow(): ?\idioma
    {
        return $this->model->get_default() ?: null;
    }

    public function save(\idioma $idioma): bool
    {
        return $idioma->save();
    }

    public function setDefault(string $codidioma): bool
    {
        return $this->model->set_default($codidioma);
    }

    public function delete(\idioma $idioma): bool
    {
        return $idioma->delete();
    }

    /**
     * Test/inspection seam exposing the underlying model.
     */
    public function model(): \idioma
    {
        return $this->model;
    }
}
