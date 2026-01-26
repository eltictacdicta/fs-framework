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

use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use FSFramework\Translation\FS2025JsonLoader;

/**
 * FSTranslator - Sistema de internacionalización para FSFramework
 * 
 * Wrapper sobre Symfony Translation Component que proporciona:
 * - Carga automática de traducciones del core y plugins
 * - Soporte para archivos YAML (nuevo formato) y JSON (compatibilidad FS2025)
 * - Fallback automático de locales (ej: es_AR -> es -> en)
 * - API simplificada con métodos estáticos
 * 
 * @example
 * // Uso básico
 * FSTranslator::trans('login-text');
 * 
 * // Con parámetros
 * FSTranslator::trans('hello', ['%name%' => 'Juan']);
 * 
 * // Cambiar idioma
 * FSTranslator::setLocale('en_EN');
 */
class FSTranslator
{
    /** @var Translator|null Instancia singleton del traductor Symfony */
    private static ?Translator $instance = null;

    /** @var string Locale actual */
    private static string $locale = 'es_ES';

    /** @var string Locale por defecto (fallback final) */
    private static string $defaultLocale = 'es_ES';

    /** @var array<string> Plugins ya cargados para evitar duplicados */
    private static array $loadedPlugins = [];

    /** @var bool Si el traductor ya fue inicializado */
    private static bool $initialized = false;

    /** @var string|null Ruta base del framework */
    private static ?string $basePath = null;

    /**
     * Obtiene o crea la instancia del traductor Symfony
     * 
     * @return Translator
     */
    public static function getInstance(): Translator
    {
        if (self::$instance === null) {
            self::initialize();
        }

        return self::$instance;
    }

    /**
     * Inicializa el sistema de traducción
     * 
     * @param string|null $basePath Ruta base del framework (opcional, autodetecta)
     * @return void
     */
    public static function initialize(?string $basePath = null): void
    {
        if (self::$initialized && self::$instance !== null) {
            return;
        }

        // Determinar ruta base
        self::$basePath = $basePath ?? self::detectBasePath();

        // Crear instancia del traductor
        self::$instance = new Translator(self::$locale);

        // Registrar loaders
        self::$instance->addLoader('yaml', new YamlFileLoader());
        self::$instance->addLoader('json', new JsonFileLoader());
        self::$instance->addLoader('fs2025json', new FS2025JsonLoader());

        // Configurar fallbacks: locale específico -> idioma base -> idioma por defecto
        self::configureFallbacks();

        // Cargar traducciones del core
        self::loadCoreTranslations();

        self::$initialized = true;
    }

    /**
     * Traduce un mensaje
     * 
     * @param string|null $id Clave del mensaje a traducir
     * @param array $parameters Parámetros de sustitución (ej: ['%name%' => 'Juan'])
     * @param string|null $domain Dominio de traducción (default: 'messages')
     * @param string|null $locale Locale específico (opcional, usa el actual)
     * @return string Mensaje traducido o la clave si no existe
     */
    public static function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        $translator = self::getInstance();
        
        return $translator->trans($id, $parameters, $domain ?? 'messages', $locale);
    }

    /**
     * Obtiene el locale actual
     * 
     * @return string
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Establece el locale actual
     * 
     * @param string $locale Código de locale (ej: 'es_ES', 'en_EN', 'es_AR')
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;

        if (self::$instance !== null) {
            self::$instance->setLocale($locale);
            self::configureFallbacks();
        }
    }

    /**
     * Establece el locale por defecto (fallback final)
     * 
     * @param string $locale
     * @return void
     */
    public static function setDefaultLocale(string $locale): void
    {
        self::$defaultLocale = $locale;
        
        if (self::$instance !== null) {
            self::configureFallbacks();
        }
    }

    /**
     * Carga traducciones de un plugin
     * 
     * Soporta dos formatos:
     * - Nuevo formato: plugins/MiPlugin/translations/messages.{locale}.yaml
     * - Formato FS2025: plugins/MiPlugin/Translation/{locale}.json
     * 
     * @param string $pluginName Nombre del plugin
     * @param string|null $pluginPath Ruta al plugin (opcional, autodetecta)
     * @return void
     */
    public static function loadPluginTranslations(string $pluginName, ?string $pluginPath = null): void
    {
        // Evitar cargar el mismo plugin múltiples veces
        if (in_array($pluginName, self::$loadedPlugins, true)) {
            return;
        }

        $translator = self::getInstance();
        $basePath = self::$basePath ?? self::detectBasePath();
        $pluginPath = $pluginPath ?? $basePath . '/plugins/' . $pluginName;

        // Intentar cargar formato nuevo (YAML)
        $yamlPath = $pluginPath . '/translations';
        if (is_dir($yamlPath)) {
            self::loadTranslationsFromDirectory($yamlPath, 'yaml', $pluginName);
        }

        // Intentar cargar formato FS2025 (JSON)
        $jsonPath = $pluginPath . '/Translation';
        if (is_dir($jsonPath)) {
            self::loadFS2025Translations($jsonPath, $pluginName);
        }

        self::$loadedPlugins[] = $pluginName;
    }

    /**
     * Carga traducciones de todos los plugins habilitados
     * 
     * @return void
     */
    public static function loadAllPluginTranslations(): void
    {
        $basePath = self::$basePath ?? self::detectBasePath();
        $pluginsPath = $basePath . '/plugins';

        if (!is_dir($pluginsPath)) {
            return;
        }

        // Intentar usar la lista de plugins habilitados si existe
        if (defined('PLUGINS') && is_array(constant('PLUGINS'))) {
            foreach (constant('PLUGINS') as $pluginName) {
                self::loadPluginTranslations($pluginName);
            }
        } elseif (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $pluginName) {
                self::loadPluginTranslations($pluginName);
            }
        } else {
            // Cargar todos los plugins del directorio
            foreach (scandir($pluginsPath) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (is_dir($pluginsPath . '/' . $item)) {
                    self::loadPluginTranslations($item);
                }
            }
        }
    }

    /**
     * Obtiene todos los idiomas disponibles
     * 
     * @return array<string, string> Array [codigo => nombre traducido]
     */
    public static function getAvailableLanguages(): array
    {
        $languages = [];
        $basePath = self::$basePath ?? self::detectBasePath();
        
        // Buscar en traducciones del core
        $translationsPath = $basePath . '/translations';
        if (is_dir($translationsPath)) {
            foreach (scandir($translationsPath) as $file) {
                if (preg_match('/^messages\.([a-z]{2}(?:_[A-Z]{2})?)\.ya?ml$/i', $file, $matches)) {
                    $langCode = $matches[1];
                    $languages[$langCode] = self::trans('languages-' . $langCode) ?: $langCode;
                }
            }
        }

        return $languages;
    }

    /**
     * Reinicia el traductor (útil para tests)
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$initialized = false;
        self::$loadedPlugins = [];
        self::$locale = 'es_ES';
    }

    /**
     * Detecta la ruta base del framework
     * 
     * @return string
     */
    private static function detectBasePath(): string
    {
        // Si hay constante FS_FOLDER definida (compatibilidad)
        if (defined('FS_FOLDER')) {
            return constant('FS_FOLDER');
        }

        // Detectar desde la ubicación del archivo
        return dirname(__DIR__, 2);
    }

    /**
     * Configura los fallbacks de locales
     * 
     * Ejemplo: es_AR -> es -> es_ES (default)
     * 
     * @return void
     */
    private static function configureFallbacks(): void
    {
        if (self::$instance === null) {
            return;
        }

        $fallbacks = [];
        $locale = self::$locale;

        // Si el locale tiene región (ej: es_AR), agregar el idioma base (es)
        if (strpos($locale, '_') !== false) {
            $baseLang = substr($locale, 0, 2);
            $fallbacks[] = $baseLang;
            
            // Si el idioma base es diferente al default, agregar también locale completo del default
            if ($baseLang !== substr(self::$defaultLocale, 0, 2)) {
                $fallbacks[] = self::$defaultLocale;
            }
        }

        // Siempre agregar el locale por defecto si es diferente al actual
        if ($locale !== self::$defaultLocale && !in_array(self::$defaultLocale, $fallbacks, true)) {
            $fallbacks[] = self::$defaultLocale;
        }

        // Agregar inglés como último recurso si no está ya
        if (!in_array('en', $fallbacks, true) && !in_array('en_EN', $fallbacks, true)) {
            $fallbacks[] = 'en';
        }

        self::$instance->setFallbackLocales($fallbacks);
    }

    /**
     * Carga las traducciones del core del framework
     * 
     * @return void
     */
    private static function loadCoreTranslations(): void
    {
        $basePath = self::$basePath ?? self::detectBasePath();
        $translationsPath = $basePath . '/translations';

        if (!is_dir($translationsPath)) {
            return;
        }

        self::loadTranslationsFromDirectory($translationsPath, 'yaml', 'core');
    }

    /**
     * Carga traducciones desde un directorio
     * 
     * @param string $directory Ruta al directorio
     * @param string $format Formato de archivos ('yaml' o 'json')
     * @param string $source Identificador de la fuente (para logging)
     * @return void
     */
    private static function loadTranslationsFromDirectory(string $directory, string $format, string $source): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $translator = self::$instance;
        $extension = $format === 'yaml' ? 'ya?ml' : 'json';
        $pattern = '/^([a-zA-Z_]+)\.([a-z]{2}(?:_[A-Z]{2})?)\.' . $extension . '$/i';

        foreach (scandir($directory) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (preg_match($pattern, $file, $matches)) {
                $domain = $matches[1];
                $locale = $matches[2];
                $filePath = $directory . '/' . $file;

                // Normalizar extensión para el loader
                $loaderFormat = (pathinfo($file, PATHINFO_EXTENSION) === 'yml') ? 'yaml' : $format;

                $translator->addResource($loaderFormat, $filePath, $locale, $domain);
            }
        }
    }

    /**
     * Carga traducciones en formato FacturaScripts 2025 (JSON)
     * 
     * Formato FS2025: Translation/{locale}.json
     * Donde el archivo contiene un objeto plano {"key": "value"}
     * 
     * @param string $directory Ruta al directorio Translation/
     * @param string $pluginName Nombre del plugin (para identificación)
     * @return void
     */
    private static function loadFS2025Translations(string $directory, string $pluginName): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $translator = self::$instance;

        foreach (scandir($directory) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Ignorar archivos PHP como updater.php
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            // Formato: {locale}.json (ej: es_ES.json, en_EN.json)
            if (preg_match('/^([a-z]{2}(?:_[A-Z]{2})?)\.json$/i', $file, $matches)) {
                $locale = $matches[1];
                $filePath = $directory . '/' . $file;

                // Usar el loader especializado para FS2025
                $translator->addResource('fs2025json', $filePath, $locale, 'messages');
            }
        }
    }
}
