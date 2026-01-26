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

namespace FSFramework\Twig;

use FSFramework\Translation\FSTranslator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Extensión Twig para internacionalización
 * 
 * Proporciona funciones y filtros para traducir textos en plantillas Twig.
 * 
 * @example
 * {# Como función #}
 * {{ trans('login-text') }}
 * {{ trans('hello', {'%name%': 'Juan'}) }}
 * 
 * {# Como filtro #}
 * {{ 'login-text'|trans }}
 * {{ 'hello'|trans({'%name%': 'Juan'}) }}
 * 
 * {# Obtener locale actual #}
 * {{ getLocale() }}
 * 
 * {# Obtener idiomas disponibles #}
 * {% for code, name in getAvailableLanguages() %}
 *     <option value="{{ code }}">{{ name }}</option>
 * {% endfor %}
 */
class TranslationExtension extends AbstractExtension
{
    /**
     * @return string Nombre de la extensión
     */
    public function getName(): string
    {
        return 'fsframework_translation';
    }

    /**
     * Registra las funciones Twig disponibles
     * 
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            // Función principal de traducción
            new TwigFunction('trans', [$this, 'trans']),
            
            // Alias de trans para compatibilidad con FS2025
            new TwigFunction('__', [$this, 'trans']),
            
            // Función para pluralización (ICU MessageFormat)
            new TwigFunction('trans_choice', [$this, 'transChoice']),
            
            // Obtener locale actual
            new TwigFunction('getLocale', [$this, 'getLocale']),
            
            // Obtener idiomas disponibles
            new TwigFunction('getAvailableLanguages', [$this, 'getAvailableLanguages']),
        ];
    }

    /**
     * Registra los filtros Twig disponibles
     * 
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            // Filtro de traducción (permite usar 'texto'|trans)
            new TwigFilter('trans', [$this, 'trans']),
            
            // Filtro de pluralización
            new TwigFilter('trans_choice', [$this, 'transChoice']),
        ];
    }

    /**
     * Traduce un mensaje
     * 
     * @param string|null $id Clave del mensaje
     * @param array $parameters Parámetros de sustitución
     * @param string|null $domain Dominio de traducción
     * @param string|null $locale Locale específico
     * @return string
     */
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return FSTranslator::trans($id, $parameters, $domain, $locale);
    }

    /**
     * Traduce un mensaje con soporte para pluralización
     * 
     * Usa ICU MessageFormat para plurales complejos.
     * 
     * @example
     * # En el archivo de traducción (YAML):
     * apples: "{count, plural, one {# manzana} other {# manzanas}}"
     * 
     * # En Twig:
     * {{ trans_choice('apples', 5, {'count': 5}) }}
     * # Resultado: "5 manzanas"
     * 
     * @param string|null $id Clave del mensaje
     * @param int $count Número para determinar la forma plural
     * @param array $parameters Parámetros de sustitución (debe incluir el count si se usa en el mensaje)
     * @param string|null $domain Dominio de traducción
     * @param string|null $locale Locale específico
     * @return string
     */
    public function transChoice(?string $id, int $count, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        // Agregar count a los parámetros si no está
        if (!isset($parameters['%count%'])) {
            $parameters['%count%'] = $count;
        }
        if (!isset($parameters['count'])) {
            $parameters['count'] = $count;
        }

        return FSTranslator::trans($id, $parameters, $domain, $locale);
    }

    /**
     * Obtiene el locale actual
     * 
     * @return string
     */
    public function getLocale(): string
    {
        return FSTranslator::getLocale();
    }

    /**
     * Obtiene los idiomas disponibles
     * 
     * @return array<string, string>
     */
    public function getAvailableLanguages(): array
    {
        return FSTranslator::getAvailableLanguages();
    }
}
