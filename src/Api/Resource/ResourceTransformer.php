<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Api\Resource;

use FSFramework\Api\Attribute\ApiField;
use FSFramework\Api\Attribute\ApiHidden;
use ReflectionClass;
use ReflectionProperty;

/**
 * Transforma modelos a arrays respetando atributos ApiField y ApiHidden
 *
 * @author FacturaScripts Team
 */
class ResourceTransformer
{
    /** @var array<string, array{hidden: string[], fields: array<string, ApiField>}> Cache de metadata */
    private static array $metadataCache = [];

    /**
     * Transforma un modelo a array para la API
     *
     * @param object $model Modelo a transformar
     * @param string[] $hiddenFields Campos adicionales a ocultar
     * @return array<string, mixed>
     */
    public function toArray(object $model, array $hiddenFields = []): array
    {
        $metadata = $this->getMetadata(get_class($model));
        $result = [];

        // Obtener propiedades públicas del modelo
        $reflection = new ReflectionClass($model);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name = $property->getName();

            // Saltar campos ocultos
            if (in_array($name, $metadata['hidden'], true) || in_array($name, $hiddenFields, true)) {
                continue;
            }

            // Verificar ApiField
            if (isset($metadata['fields'][$name])) {
                $fieldAttr = $metadata['fields'][$name];
                if (!$fieldAttr->readable) {
                    continue;
                }
                // Usar nombre alternativo si se especificó
                $outputName = $fieldAttr->name ?? $name;
            } else {
                $outputName = $name;
            }

            // Obtener valor
            $value = $property->getValue($model);

            // Transformar valor según tipo
            $result[$outputName] = $this->transformValue($value);
        }

        // Si el modelo tiene método toArray(), combinar resultados
        if (method_exists($model, 'toArray')) {
            $modelArray = $model->toArray();
            foreach ($modelArray as $key => $value) {
                if (!in_array($key, $metadata['hidden'], true) && !isset($result[$key])) {
                    $result[$key] = $this->transformValue($value);
                }
            }
        }

        return $result;
    }

    /**
     * Transforma múltiples modelos
     *
     * @param object[] $models
     * @param string[] $hiddenFields
     * @return array<array<string, mixed>>
     */
    public function toArrayList(array $models, array $hiddenFields = []): array
    {
        return array_map(fn($model) => $this->toArray($model, $hiddenFields), $models);
    }

    /**
     * Filtra datos de entrada según campos escribibles
     *
     * @param string $modelClass
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterWritableFields(string $modelClass, array $data): array
    {
        $metadata = $this->getMetadata($modelClass);
        $filtered = [];

        foreach ($data as $key => $value) {
            // Si hay ApiField, verificar si es writable
            if (isset($metadata['fields'][$key])) {
                if ($metadata['fields'][$key]->writable) {
                    $filtered[$key] = $value;
                }
            } else {
                // Sin ApiField, permitir por defecto (a menos que esté oculto)
                if (!in_array($key, $metadata['hidden'], true)) {
                    $filtered[$key] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Obtiene campos requeridos de un modelo
     *
     * @return string[]
     */
    public function getRequiredFields(string $modelClass): array
    {
        $metadata = $this->getMetadata($modelClass);
        $required = [];

        foreach ($metadata['fields'] as $name => $field) {
            if ($field->required && $field->writable) {
                $required[] = $field->name ?? $name;
            }
        }

        return $required;
    }

    /**
     * Obtiene metadata de un modelo (con cache)
     *
     * @return array{hidden: string[], fields: array<string, ApiField>}
     */
    private function getMetadata(string $class): array
    {
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $metadata = [
            'hidden' => [],
            'fields' => []
        ];

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name = $property->getName();

            // Verificar ApiHidden
            $hiddenAttrs = $property->getAttributes(ApiHidden::class);
            if (!empty($hiddenAttrs)) {
                $metadata['hidden'][] = $name;
                continue;
            }

            // Verificar ApiField
            $fieldAttrs = $property->getAttributes(ApiField::class);
            if (!empty($fieldAttrs)) {
                $metadata['fields'][$name] = $fieldAttrs[0]->newInstance();
            }
        }

        self::$metadataCache[$class] = $metadata;
        return $metadata;
    }

    /**
     * Transforma un valor individual
     */
    private function transformValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Objetos con toArray
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        // DateTime
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c'); // ISO 8601
        }

        // Arrays recursivos
        if (is_array($value)) {
            return array_map(fn($v) => $this->transformValue($v), $value);
        }

        return $value;
    }

    /**
     * Limpia el cache (útil para tests)
     */
    public static function clearCache(): void
    {
        self::$metadataCache = [];
    }
}
