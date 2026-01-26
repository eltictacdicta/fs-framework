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

namespace FSFramework\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Clase base para eventos de FSFramework.
 * Soporta integración con extensiones legacy.
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
abstract class FSEvent extends Event
{
    /**
     * Extensiones legacy asociadas a este evento.
     * @var array
     */
    protected array $legacyExtensions = [];

    /**
     * Datos adicionales del evento.
     * @var array
     */
    protected array $data = [];

    /**
     * Añade una extensión legacy al evento.
     */
    public function addLegacyExtension(object $extension): self
    {
        $this->legacyExtensions[] = $extension;
        return $this;
    }

    /**
     * Obtiene las extensiones legacy asociadas.
     */
    public function getLegacyExtensions(): array
    {
        return $this->legacyExtensions;
    }

    /**
     * Procesa las extensiones legacy.
     * Las clases hijas pueden sobrescribir para lógica específica.
     */
    public function processLegacyExtensions(): void
    {
        // Implementación por defecto vacía
        // Las clases hijas pueden sobrescribir
    }

    /**
     * Establece datos adicionales.
     */
    public function setData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Obtiene todos los datos.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Obtiene un valor específico de los datos.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Establece un valor específico en los datos.
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }
}
