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

namespace FSFramework\Translation;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Loader para archivos de traducción JSON en formato FacturaScripts 2025
 * 
 * Este loader es compatible con el formato de traducción usado en FS2025:
 * - Archivos JSON con estructura plana: {"key": "value", ...}
 * - Ubicados en Plugin/Translation/{locale}.json
 * 
 * Diferencias con el JsonFileLoader estándar de Symfony:
 * - Soporta la estructura plana de FS2025 (sin dominios anidados)
 * - Maneja errores de forma más tolerante para plugins legacy
 * - Log de traducciones no encontradas para debugging
 * 
 * @example
 * // Archivo Translation/es_ES.json
 * {
 *     "backup": "Copia de seguridad",
 *     "restore-backup": "Restaurar copia",
 *     "download-backup": "Descargar copia"
 * }
 */
class FS2025JsonLoader implements LoaderInterface
{
    /**
     * {@inheritdoc}
     */
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_string($resource)) {
            throw new InvalidResourceException('The resource must be a file path.');
        }

        if (!stream_is_local($resource)) {
            throw new InvalidResourceException(sprintf('The resource "%s" is not a local file.', $resource));
        }

        if (!file_exists($resource)) {
            throw new NotFoundResourceException(sprintf('File "%s" not found.', $resource));
        }

        $messages = $this->loadJsonFile($resource);

        $catalogue = new MessageCatalogue($locale);
        $catalogue->add($messages, $domain);

        // Registrar el recurso para el mecanismo de caché de Symfony
        if (class_exists('Symfony\Component\Config\Resource\FileResource')) {
            $catalogue->addResource(new \Symfony\Component\Config\Resource\FileResource($resource));
        }

        return $catalogue;
    }

    /**
     * Carga y parsea un archivo JSON de traducción
     * 
     * @param string $resource Ruta al archivo JSON
     * @return array<string, string> Array de traducciones [clave => valor]
     * @throws InvalidResourceException Si el archivo no es JSON válido
     */
    private function loadJsonFile(string $resource): array
    {
        $content = file_get_contents($resource);
        
        if ($content === false) {
            throw new InvalidResourceException(sprintf('Unable to read file "%s".', $resource));
        }

        // Manejar archivos vacíos
        $content = trim($content);
        if ($content === '' || $content === '{}') {
            return [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidResourceException(sprintf(
                'Error parsing JSON in file "%s": %s',
                $resource,
                json_last_error_msg()
            ));
        }

        if (!is_array($data)) {
            throw new InvalidResourceException(sprintf(
                'The file "%s" must contain a JSON object.',
                $resource
            ));
        }

        // Aplanar el array si tiene estructura anidada (compatibilidad)
        return $this->flattenArray($data);
    }

    /**
     * Aplana un array anidado a formato plano
     * 
     * FacturaScripts usa formato plano, pero por compatibilidad soportamos
     * estructura anidada también:
     * 
     * Input:  {"user": {"login": "Entrar", "logout": "Salir"}}
     * Output: {"user.login": "Entrar", "user.logout": "Salir"}
     * 
     * @param array $array Array potencialmente anidado
     * @param string $prefix Prefijo para las claves (usado en recursión)
     * @return array<string, string> Array aplanado
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_array($value)) {
                // Recursivamente aplanar arrays anidados
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                // Guardar valor como string
                $result[$newKey] = (string) $value;
            }
        }

        return $result;
    }
}
