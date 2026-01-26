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

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Dispatcher de eventos compatible con el sistema de extensiones legacy.
 * 
 * Este dispatcher extiende Symfony EventDispatcher y añade soporte
 * para las extensiones legacy de FSFramework (fs_extension).
 * 
 * Eventos disponibles:
 * - controller.before_action: Antes de ejecutar private_core()
 * - controller.after_action: Después de ejecutar private_core()
 * - model.before_save: Antes de guardar un modelo
 * - model.after_save: Después de guardar un modelo
 * - model.before_delete: Antes de eliminar un modelo
 * - model.after_delete: Después de eliminar un modelo
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class FSEventDispatcher extends EventDispatcher
{
    private static ?FSEventDispatcher $instance = null;

    /**
     * Obtiene la instancia singleton del dispatcher.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerLegacyExtensions();
        }
        return self::$instance;
    }

    /**
     * Registra extensiones legacy como listeners de eventos.
     * Convierte el sistema de fs_extension a eventos Symfony.
     */
    private function registerLegacyExtensions(): void
    {
        if (!class_exists('fs_extension')) {
            return;
        }

        try {
            $fsext = new \fs_extension();
            $extensions = $fsext->all();

            foreach ($extensions as $ext) {
                $this->registerLegacyExtension($ext);
            }
        } catch (\Throwable $e) {
            error_log('FSEventDispatcher: Error loading legacy extensions: ' . $e->getMessage());
        }
    }

    /**
     * Registra una extensión legacy individual.
     */
    private function registerLegacyExtension(object $ext): void
    {
        // Mapeo de tipos de extensión legacy a eventos
        $typeToEvent = [
            'button' => 'controller.render',
            'tab' => 'controller.render',
            'config' => 'controller.config',
            'css' => 'view.assets',
            'js' => 'view.assets',
        ];

        if (!isset($typeToEvent[$ext->type])) {
            return;
        }

        $eventName = $typeToEvent[$ext->type];
        
        // Crear listener que ejecuta la extensión legacy
        $this->addListener($eventName, function ($event) use ($ext) {
            if ($event instanceof FSEvent) {
                $event->addLegacyExtension($ext);
            }
        });
    }

    /**
     * Dispara un evento y también ejecuta extensiones legacy compatibles.
     * 
     * @param object $event El evento a disparar
     * @param string|null $eventName Nombre del evento
     * @return object El evento (posiblemente modificado)
     */
    public function dispatchWithLegacy(object $event, ?string $eventName = null): object
    {
        // Disparar listeners modernos de Symfony
        $this->dispatch($event, $eventName);

        // Si el evento tiene método para procesar extensiones legacy, ejecutarlo
        if (method_exists($event, 'processLegacyExtensions')) {
            $event->processLegacyExtensions();
        }

        return $event;
    }

    /**
     * Atajo para disparar eventos de controlador.
     */
    public function dispatchControllerEvent(string $action, string $controllerName, array $data = []): ControllerEvent
    {
        $event = new ControllerEvent($controllerName, $action, $data);
        $eventName = "controller.{$action}";
        
        $this->dispatch($event, $eventName);
        
        return $event;
    }

    /**
     * Atajo para disparar eventos de modelo.
     */
    public function dispatchModelEvent(string $action, object $model): ModelEvent
    {
        $event = new ModelEvent($model, $action);
        $eventName = "model.{$action}";
        
        $this->dispatch($event, $eventName);
        
        return $event;
    }

    /**
     * Reinicia la instancia singleton (útil para tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
