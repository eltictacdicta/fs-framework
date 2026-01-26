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
 * Evento disparado durante el ciclo de vida del controlador.
 * 
 * Eventos disponibles:
 * - controller.before_action: Antes de private_core()
 * - controller.after_action: Después de private_core()
 * - controller.render: Durante el renderizado de la vista
 * - controller.config: Para extensiones de configuración
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class ControllerEvent extends FSEvent
{
    public const BEFORE_ACTION = 'controller.before_action';
    public const AFTER_ACTION = 'controller.after_action';
    public const RENDER = 'controller.render';
    public const CONFIG = 'controller.config';

    private string $controllerName;
    private string $action;
    private ?object $controller = null;
    private ?\Symfony\Component\HttpFoundation\Response $response = null;

    public function __construct(string $controllerName, string $action, array $data = [])
    {
        $this->controllerName = $controllerName;
        $this->action = $action;
        $this->data = $data;
    }

    /**
     * Nombre del controlador que dispara el evento.
     */
    public function getControllerName(): string
    {
        return $this->controllerName;
    }

    /**
     * Acción que se está ejecutando.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Establece la instancia del controlador.
     */
    public function setController(object $controller): self
    {
        $this->controller = $controller;
        return $this;
    }

    /**
     * Obtiene la instancia del controlador.
     */
    public function getController(): ?object
    {
        return $this->controller;
    }

    /**
     * Establece una respuesta para cortocircuitar el flujo.
     */
    public function setResponse(\Symfony\Component\HttpFoundation\Response $response): self
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Obtiene la respuesta establecida.
     */
    public function getResponse(): ?\Symfony\Component\HttpFoundation\Response
    {
        return $this->response;
    }

    /**
     * Indica si se ha establecido una respuesta.
     */
    public function hasResponse(): bool
    {
        return $this->response !== null;
    }

    /**
     * Procesa extensiones legacy de tipo 'button' y 'tab'.
     */
    public function processLegacyExtensions(): void
    {
        foreach ($this->legacyExtensions as $ext) {
            // Las extensiones legacy se manejan en la vista
            // Aquí solo las recolectamos para pasarlas al template
            if (!isset($this->data['extensions'])) {
                $this->data['extensions'] = [];
            }
            $this->data['extensions'][] = $ext;
        }
    }
}
