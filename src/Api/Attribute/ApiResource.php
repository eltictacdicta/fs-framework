<?php
/**
 * This file is part of FacturaScripts
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

namespace FSFramework\Api\Attribute;

use Attribute;

/**
 * Atributo para marcar un modelo como recurso de la API
 *
 * Uso:
 * ```php
 * #[ApiResource(
 *     operations: [Operation::LIST, Operation::GET],
 *     version: 'v1',
 *     searchable: ['nombre', 'email'],
 *     sortable: ['nombre', 'fechaalta'],
 *     filterable: ['activo']
 * )]
 * class cliente extends fs_model { ... }
 * ```
 *
 * @author FacturaScripts Team
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ApiResource
{
    /**
     * @param Operation[] $operations Operaciones permitidas (ninguna por defecto = seguro)
     * @param string $version Versión de la API
     * @param string|null $resource Nombre del recurso (se deduce del nombre de clase si no se especifica)
     * @param string|null $plugin Nombre del plugin (se detecta automáticamente)
     * @param string[] $searchable Campos por los que se puede buscar
     * @param string[] $sortable Campos por los que se puede ordenar
     * @param string[] $filterable Campos por los que se puede filtrar
     * @param int $perPage Elementos por página por defecto
     * @param int $maxPerPage Máximo elementos por página
     * @param bool $requiresAuth Requiere autenticación (true por defecto)
     * @param string|null $description Descripción para documentación
     */
    public function __construct(
        public array $operations = [],
        public string $version = 'v1',
        public ?string $resource = null,
        public ?string $plugin = null,
        public array $searchable = [],
        public array $sortable = [],
        public array $filterable = [],
        public int $perPage = 50,
        public int $maxPerPage = 100,
        public bool $requiresAuth = true,
        public ?string $description = null
    ) {}

    /**
     * Verifica si una operación está permitida
     */
    public function allowsOperation(Operation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Verifica si permite lectura
     */
    public function allowsRead(): bool
    {
        return $this->allowsOperation(Operation::LIST) || $this->allowsOperation(Operation::GET);
    }

    /**
     * Verifica si permite escritura
     */
    public function allowsWrite(): bool
    {
        return $this->allowsOperation(Operation::CREATE) 
            || $this->allowsOperation(Operation::UPDATE)
            || $this->allowsOperation(Operation::DELETE);
    }

    /**
     * Verifica si un campo es buscable
     */
    public function isSearchable(string $field): bool
    {
        return in_array($field, $this->searchable, true);
    }

    /**
     * Verifica si un campo es ordenable
     */
    public function isSortable(string $field): bool
    {
        return in_array($field, $this->sortable, true);
    }

    /**
     * Verifica si un campo es filtrable
     */
    public function isFilterable(string $field): bool
    {
        return in_array($field, $this->filterable, true);
    }
}
