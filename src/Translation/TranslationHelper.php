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

/**
 * Helper de traducción para uso simplificado en código PHP
 * 
 * Proporciona funciones globales y métodos estáticos para traducción
 * sin necesidad de instanciar objetos.
 * 
 * @example
 * // Función global (registrada automáticamente)
 * echo __('login-text');
 * echo __('hello', ['%name%' => 'Juan']);
 * 
 * // Métodos estáticos
 * TranslationHelper::trans('user');
 * TranslationHelper::setLocale('en_EN');
 */
class TranslationHelper
{
    /**
     * Traduce un mensaje
     * 
     * @param string|null $id Clave del mensaje
     * @param array $parameters Parámetros de sustitución
     * @param string|null $domain Dominio (default: messages)
     * @param string|null $locale Locale específico
     * @return string
     */
    public static function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return FSTranslator::trans($id, $parameters, $domain, $locale);
    }

    /**
     * Obtiene el locale actual
     * 
     * @return string
     */
    public static function getLocale(): string
    {
        return FSTranslator::getLocale();
    }

    /**
     * Establece el locale
     * 
     * @param string $locale
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        FSTranslator::setLocale($locale);
    }

    /**
     * Obtiene los idiomas disponibles
     * 
     * @return array<string, string>
     */
    public static function getAvailableLanguages(): array
    {
        return FSTranslator::getAvailableLanguages();
    }

    /**
     * Inicializa el sistema de traducción con configuración del sistema
     * 
     * Lee el locale desde:
     * 1. Constante FS_LANG si está definida
     * 2. Configuración de la empresa si existe
     * 3. Locale por defecto (es_ES)
     * 
     * @param string|null $basePath Ruta base del framework
     * @return void
     */
    public static function initializeFromConfig(?string $basePath = null): void
    {
        FSTranslator::initialize($basePath);

        // Determinar locale desde la configuración
        $locale = self::detectLocale();
        if ($locale) {
            FSTranslator::setLocale($locale);
        }

        // Cargar traducciones de plugins
        FSTranslator::loadAllPluginTranslations();
    }

    /**
     * Detecta el locale desde la configuración del sistema
     * 
     * @return string|null Locale detectado o null
     */
    private static function detectLocale(): ?string
    {
        // 1. Constante FS_LANG
        if (defined('FS_LANG') && !empty(constant('FS_LANG'))) {
            return constant('FS_LANG');
        }

        // 2. Configuración de usuario (si está logueado)
        // Esta funcionalidad puede expandirse para leer del usuario actual

        // 3. Configuración de empresa
        // Esto requiere acceso a la base de datos, se puede implementar más adelante

        return null;
    }

    /**
     * Registra las funciones globales de traducción
     * 
     * Crea la función global __() si no existe.
     * Esta función se puede llamar durante el bootstrap de la aplicación.
     * 
     * @return void
     */
    public static function registerGlobalFunctions(): void
    {
        // La función global se registra en functions.php
        // Este método es para uso explícito si se necesita
    }
}

// ============================================
// Funciones globales de traducción
// ============================================

if (!function_exists('__')) {
    /**
     * Función global de traducción (shortcut)
     * 
     * @param string|null $id Clave del mensaje
     * @param array $parameters Parámetros de sustitución
     * @param string|null $domain Dominio
     * @param string|null $locale Locale
     * @return string
     */
    function __(string $id = null, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return FSTranslator::trans($id, $parameters, $domain, $locale);
    }
}

if (!function_exists('trans')) {
    /**
     * Función global de traducción (alias de __)
     * 
     * @param string|null $id Clave del mensaje
     * @param array $parameters Parámetros de sustitución
     * @param string|null $domain Dominio
     * @param string|null $locale Locale
     * @return string
     */
    function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return FSTranslator::trans($id, $parameters, $domain, $locale);
    }
}
