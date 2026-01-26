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

/**
 * Evento disparado durante operaciones de modelo.
 * 
 * Eventos disponibles:
 * - model.before_save: Antes de save()
 * - model.after_save: Después de save() exitoso
 * - model.before_delete: Antes de delete()
 * - model.after_delete: Después de delete() exitoso
 * - model.before_test: Antes de test() (validación)
 * - model.after_test: Después de test()
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class ModelEvent extends FSEvent
{
    public const BEFORE_SAVE = 'model.before_save';
    public const AFTER_SAVE = 'model.after_save';
    public const BEFORE_DELETE = 'model.before_delete';
    public const AFTER_DELETE = 'model.after_delete';
    public const BEFORE_TEST = 'model.before_test';
    public const AFTER_TEST = 'model.after_test';

    private object $model;
    private string $action;
    private bool $cancelled = false;
    private ?string $cancelReason = null;

    public function __construct(object $model, string $action)
    {
        $this->model = $model;
        $this->action = $action;
    }

    /**
     * Obtiene el modelo afectado.
     */
    public function getModel(): object
    {
        return $this->model;
    }

    /**
     * Nombre de la clase del modelo.
     */
    public function getModelClass(): string
    {
        return get_class($this->model);
    }

    /**
     * Nombre de la tabla del modelo (si es fs_model).
     */
    public function getTableName(): ?string
    {
        if (property_exists($this->model, 'table_name')) {
            return $this->model->table_name;
        }
        return null;
    }

    /**
     * Acción que disparó el evento.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Cancela la operación (solo para eventos 'before_*').
     */
    public function cancel(string $reason = ''): self
    {
        $this->cancelled = true;
        $this->cancelReason = $reason;
        $this->stopPropagation();
        return $this;
    }

    /**
     * Indica si la operación fue cancelada.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Obtiene la razón de la cancelación.
     */
    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    /**
     * Verifica si el modelo es de un tipo específico.
     */
    public function isModelType(string $className): bool
    {
        return $this->model instanceof $className;
    }

    /**
     * Obtiene un atributo del modelo.
     */
    public function getModelAttribute(string $name): mixed
    {
        if (property_exists($this->model, $name)) {
            return $this->model->$name;
        }
        return null;
    }

    /**
     * Establece un atributo del modelo.
     */
    public function setModelAttribute(string $name, mixed $value): self
    {
        if (property_exists($this->model, $name)) {
            $this->model->$name = $value;
        }
        return $this;
    }
}
