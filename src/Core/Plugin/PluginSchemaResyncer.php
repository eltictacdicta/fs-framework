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

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

/**
 * Re-sincronización de esquema BD con visibilidad de dependencias en memoria.
 *
 * Resuelve el fallo de "Archivo model/table/X.xml no encontrado": durante el
 * sync de esquema de un plugin, sus dependencias instaladas (aunque estén
 * desactivadas) deben ser visibles para la resolución de XMLs de tablas.
 *
 * La visibilidad se aplica SOLO en memoria: se hace snapshot de
 * $GLOBALS['plugins'], se añade el plugin y sus dependencias instaladas, se
 * ejecuta el callback y se restaura el snapshot en un bloque finally. Ninguna
 * dependencia se habilita de forma persistente.
 *
 * Servicio centralizado del core (SS-05); referencia \fs_plugin_manager
 * (clase legacy base) igual que PluginSchemaSynchronizer.
 */
final class PluginSchemaResyncer
{
    /**
     * Re-ejecuta applyPluginSchemaUpdates para los plugins instalados (o uno
     * concreto vía $only) en orden de dependencias, sin descargar nada.
     *
     * @param callable(string): list<string>|null $requirementsFn
     * @param callable(string): bool|null $isInstalledFn
     *
     * @return array{
     *     success: bool,
     *     updated: list<string>,
     *     failed: list<string>,
     *     messages: list<string>,
     *     results: array<string, array{success: bool, changes: list<string>, errors: list<string>}>
     * }
     */
    public static function resyncInstalled(
        \fs_plugin_manager $manager,
        ?string $only = null,
        ?callable $requirementsFn = null,
        ?callable $isInstalledFn = null
    ): array {
        $installed = [];
        foreach ($manager->installed() as $plugin) {
            $name = (string) ($plugin['name'] ?? '');
            if ($name !== '') {
                $installed[] = $name;
            }
        }

        $targets = $only !== null && $only !== ''
            ? PluginUpdateOrderer::order([$only], $requirementsFn, $isInstalledFn)
            : PluginUpdateOrderer::order($installed, $requirementsFn, $isInstalledFn);

        $snapshot = $GLOBALS['plugins'] ?? [];
        $updated = [];
        $failed = [];
        $messages = [];
        $results = [];

        try {
            foreach ($targets as $name) {
                self::withDependencyVisibility(
                    $name,
                    static function () use ($manager, $name, &$results, &$updated, &$failed, &$messages): void {
                        $result = $manager->applyPluginSchemaUpdates($name);
                        $results[$name] = $result;

                        [$status, $message] = self::classifyResult($name, $result);
                        if ($status === 'updated') {
                            $updated[] = $name;

                            return;
                        }

                        $failed[] = $name;
                        $messages[] = $message;
                    },
                    $requirementsFn,
                    $isInstalledFn
                );
            }
        } finally {
            $GLOBALS['plugins'] = $snapshot;
        }

        return [
            'success' => $failed === [],
            'updated' => $updated,
            'failed' => $failed,
            'messages' => $messages,
            'results' => $results,
        ];
    }

    /**
     * Añade en memoria el plugin y sus dependencias instaladas a
     * $GLOBALS['plugins'], ejecuta el callback y restaura el snapshot.
     *
     * Si el callback lanza una excepción, el snapshot se restaura igualmente
     * (finally) y la excepción se re-lanza.
     *
     * @param callable(): mixed $callback
     * @param callable(string): list<string>|null $requirementsFn
     * @param callable(string): bool|null $isInstalledFn
     *
     * @return mixed Resultado del callback.
     */
    public static function withDependencyVisibility(
        string $pluginName,
        callable $callback,
        ?callable $requirementsFn = null,
        ?callable $isInstalledFn = null
    ): mixed {
        $snapshot = $GLOBALS['plugins'] ?? [];

        $visible = PluginUpdateOrderer::order([$pluginName], $requirementsFn, $isInstalledFn);
        $GLOBALS['plugins'] = array_values(array_unique(array_merge($snapshot, $visible)));

        try {
            return $callback();
        } finally {
            $GLOBALS['plugins'] = $snapshot;
        }
    }

    /**
     * Clasifica el resultado de un sync y genera el mensaje asociado.
     *
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     *
     * @return array{0: string, 1: string} ['updated'|'failed', mensaje]
     */
    private static function classifyResult(string $name, array $result): array
    {
        if ($result['success']) {
            return ['updated', ''];
        }

        $errors = $result['errors'];
        $message = 'Error al sincronizar el esquema de ' . $name
            . ($errors !== [] ? ': ' . implode('; ', $errors) : '.');

        return ['failed', $message];
    }
}