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
use Symfony\Component\Validator\Constraint;

/**
 * Atributo para configurar campos individuales en la API
 *
 * Uso:
 * ```php
 * class cliente extends fs_model {
 *     #[ApiField(readable: true, writable: false)]
 *     public $codcliente;
 *
 *     #[ApiField(
 *         readable: true,
 *         writable: true,
 *         validation: [new NotBlank(), new Length(max: 100)]
 *     )]
 *     public $nombre;
 * }
 * ```
 *
 * @author FacturaScripts Team
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ApiField
{
    /**
     * @param bool $readable El campo se incluye en respuestas GET
     * @param bool $writable El campo acepta datos en POST/PUT
     * @param string|null $name Nombre en la API (diferente al nombre de la propiedad)
     * @param string|null $description Descripción para documentación
     * @param Constraint[] $validation Constraints de Symfony Validator
     * @param string|null $type Tipo para documentación (string, integer, boolean, etc.)
     * @param mixed $default Valor por defecto si no se proporciona
     * @param bool $required Campo requerido en operaciones de escritura
     * @param string|null $format Formato especial (date, datetime, email, url, etc.)
     */
    public function __construct(
        public bool $readable = true,
        public bool $writable = true,
        public ?string $name = null,
        public ?string $description = null,
        public array $validation = [],
        public ?string $type = null,
        public mixed $default = null,
        public bool $required = false,
        public ?string $format = null
    ) {}

    /**
     * Verifica si el campo es de solo lectura
     */
    public function isReadOnly(): bool
    {
        return $this->readable && !$this->writable;
    }

    /**
     * Verifica si el campo es de solo escritura
     */
    public function isWriteOnly(): bool
    {
        return $this->writable && !$this->readable;
    }

    /**
     * Obtiene los constraints de validación
     *
     * @return Constraint[]
     */
    public function getValidationConstraints(): array
    {
        return $this->validation;
    }
}
