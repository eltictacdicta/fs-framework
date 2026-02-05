<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2015-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
require_once 'base/fs_file_manager.php';

/**
 * Description of fs_plugin_manager
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_plugin_manager
{

    /**
     *
     * @var fs_cache
     */
    private $cache;

    /**
     *
     * @var fs_core_log
     */
    private $core_log;

    /**
     *
     * @var bool
     */
    public $disable_mod_plugins = false;

    /**
     *
     * @var bool
     */
    public $disable_add_plugins = false;

    /**
     *
     * @var bool
     */
    public $disable_rm_plugins = false;

    /**
     *
     * @var array
     */
    private $download_list;

    /**
     * Lista de plugins privados disponibles para descarga
     * @var array
     */
    private $private_download_list;

    /**
     * Configuración de plugins privados (token, url)
     * @var array
     */
    private $private_config;

    /**
     * Versión de FSFramework (archivo VERSION)
     * @var float
     */
    public $version = 2025.101;

    public function __construct()
    {
        $this->cache = new fs_cache();
        $this->core_log = new fs_core_log();

        if (defined('FS_DISABLE_MOD_PLUGINS')) {
            $this->disable_mod_plugins = FS_DISABLE_MOD_PLUGINS;
            $this->disable_add_plugins = FS_DISABLE_MOD_PLUGINS;
            $this->disable_rm_plugins = FS_DISABLE_MOD_PLUGINS;
        }

        if (!$this->disable_mod_plugins) {
            if (defined('FS_DISABLE_ADD_PLUGINS')) {
                $this->disable_add_plugins = FS_DISABLE_ADD_PLUGINS;
            }

            if (defined('FS_DISABLE_RM_PLUGINS')) {
                $this->disable_rm_plugins = FS_DISABLE_RM_PLUGINS;
            }
        }

        // Cargar versión principal de FSFramework
        if (file_exists(FS_FOLDER . '/VERSION')) {
            $this->version = (float) trim(file_get_contents(FS_FOLDER . '/VERSION'));
        } elseif (class_exists('FSFramework\\Core\\Kernel')) {
            $this->version = \FSFramework\Core\Kernel::version();
        }
    }

    public function disable($plugin_name)
    {
        if (!in_array($plugin_name, $this->enabled())) {
            return true;
        }

        foreach ($GLOBALS['plugins'] as $i => $value) {
            if ($value == $plugin_name) {
                unset($GLOBALS['plugins'][$i]);
                break;
            }
        }

        if ($this->save()) {
            $this->core_log->new_message('Plugin <b>' . $plugin_name . '</b> desactivado correctamente.');
            $this->core_log->save('Plugin ' . $plugin_name . ' desactivado correctamente.', 'msg');
        } else {
            $this->core_log->new_error('Imposible desactivar el plugin <b>' . $plugin_name . '</b>.');
            return false;
        }

        /*
         * Desactivamos las páginas que ya no existen
         */
        $this->disable_unnused_pages();

        /// desactivamos los plugins que dependan de este
        foreach ($this->installed() as $plug) {
            /**
             * Si el plugin que hemos desactivado, es requerido por el plugin
             * que estamos comprobando, lo desativamos también.
             */
            if (in_array($plug['name'], $GLOBALS['plugins']) && in_array($plugin_name, $plug['require'])) {
                $this->disable($plug['name']);
            }
        }

        // Si es un plugin de soporte, desactivar los plugins que dependen de él
        $this->disable_support_dependents($plugin_name);

        $this->clean_cache();
        return true;
    }

    /**
     * Desactiva los plugins que dependen de un plugin de soporte (legacy_support o facturascripts_support).
     * La lógica de detección de dependientes está delegada a los propios plugins de soporte.
     * 
     * @param string $plugin_name Nombre del plugin de soporte que se está desactivando
     */
    private function disable_support_dependents($plugin_name)
    {
        $validator_class = null;

        if ($plugin_name === 'legacy_support') {
            $validator_class = 'FacturaScripts\\Plugins\\legacy_support\\VersionValidator';
            $validator_file = FS_FOLDER . '/plugins/legacy_support/VersionValidator.php';
        } elseif ($plugin_name === 'facturascripts_support') {
            $validator_class = 'FacturaScripts\\Plugins\\facturascripts_support\\VersionValidator';
            $validator_file = FS_FOLDER . '/plugins/facturascripts_support/VersionValidator.php';
        } else {
            return; // No es un plugin de soporte
        }

        // Cargar la clase si no está cargada
        if (!class_exists($validator_class) && file_exists($validator_file)) {
            require_once $validator_file;
        }

        if (!class_exists($validator_class) || !method_exists($validator_class, 'getDependentPlugins')) {
            return;
        }

        // Obtener los plugins dependientes desde el propio plugin de soporte
        $dependents = $validator_class::getDependentPlugins();

        foreach ($dependents as $dependent_plugin) {
            if (in_array($dependent_plugin, $GLOBALS['plugins'])) {
                $this->core_log->new_message('Desactivando <b>' . $dependent_plugin . '</b> porque depende de <b>' . $plugin_name . '</b>.');
                $this->disable($dependent_plugin);
            }
        }
    }

    public function disabled()
    {
        $disabled = [];
        if (defined('FS_DISABLED_PLUGINS')) {
            foreach (explode(',', FS_DISABLED_PLUGINS) as $aux) {
                $disabled[] = $aux;
            }
        }

        return $disabled;
    }

    public function download($plugin_id, $create_backup = false)
    {
        if ($this->disable_mod_plugins) {
            $this->core_log->new_error('No tienes permiso para descargar plugins.');
            return false;
        }

        foreach ($this->downloads() as $item) {
            if ($item['id'] != (int) $plugin_id) {
                continue;
            }

            $this->core_log->new_message('Descargando el plugin ' . $item['nombre']);
            if (!@fs_file_download($item['zip_link'], FS_FOLDER . '/download.zip')) {
                $this->core_log->new_error('Error al descargar. Tendrás que descargarlo manualmente desde '
                    . '<a href="' . $item['zip_link'] . '" target="_blank">aquí</a> y añadirlo pulsando el botón <b>añadir</b>.');
                return false;
            }

            // Crear backup si existe y se solicita
            if ($create_backup && file_exists(FS_FOLDER . '/plugins/' . $item['nombre'])) {
                if (!$this->create_backup($item['nombre'])) {
                    unlink(FS_FOLDER . '/download.zip');
                    return false;
                }
            }

            $plugins_list = fs_file_manager::scan_folder(FS_FOLDER . '/plugins');

            if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/download.zip', FS_FOLDER . '/plugins/')) {
                $this->core_log->new_error('Error al extraer el ZIP. Código de integridad o seguridad fallido.');
                unlink(FS_FOLDER . '/download.zip');
                return false;
            }
            unlink(FS_FOLDER . '/download.zip');

            /// renombramos si es necesario
            foreach (fs_file_manager::scan_folder(FS_FOLDER . '/plugins') as $f) {
                if (is_dir(FS_FOLDER . '/plugins/' . $f) && !in_array($f, $plugins_list)) {
                    // Eliminar el plugin existente si hay que sobrescribir
                    if (file_exists(FS_FOLDER . '/plugins/' . $item['nombre'])) {
                        fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $item['nombre']);
                    }
                    rename(FS_FOLDER . '/plugins/' . $f, FS_FOLDER . '/plugins/' . $item['nombre']);
                    break;
                }
            }

            $this->core_log->new_message('Plugin añadido correctamente.');
            return $this->enable($item['nombre']);
        }

        $this->core_log->new_error('Descarga no encontrada.');
        return false;
    }

    public function downloads()
    {
        if (isset($this->download_list)) {
            return $this->download_list;
        }

        /// buscamos en la cache
        $this->download_list = $this->cache->get('download_list');
        if ($this->download_list) {
            return $this->download_list;
        }

        /// lista de plugins de la comunidad, se descarga de Internet.
        $json = @fs_file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/main/custom_plugins.json', 10);
        if ($json && $json != 'ERROR') {
            $this->download_list = json_decode($json, true);
            foreach ($this->download_list as $key => $value) {
                $this->download_list[$key]['instalado'] = file_exists(FS_FOLDER . '/plugins/' . $value['nombre']);
            }

            $this->cache->set('download_list', $this->download_list);
            return $this->download_list;
        }

        $this->core_log->new_error('Error al descargar la lista de plugins.');
        $this->download_list = [
            [
                'id' => 87,
                'nick' => "NeoRazorX",
                'creador' => "NeoRazorX",
                'nombre' => "facturacion_base",
                'tipo' => "gratis",
                'descripcion' => "Plugin con las funciones básicas de facturación, contabilidad e informes simples.",
                'link' => "https://github.com/NeoRazorX/facturacion_base",
                'zip_link' => "https://github.com/NeoRazorX/facturacion_base/archive/master.zip",
                'imagen' => "",
                'estable' => true,
                'version' => 140,
                'creado' => "14-07-2016",
                'ultima_modificacion' => "30-06-2018",
                'descargas' => 130611,
                'oferta_hasta' => null,
                'caducidad' => null,
                'licencia' => "LGPL",
                'youtube_id' => "",
                'demo_url' => "",
                'precio' => 0,
                'instalado' => file_exists(FS_FOLDER . '/plugins/facturacion_base')
            ]
        ];

        return $this->download_list;
    }

    /**
     * Devuelve la configuración de plugins privados.
     * @return array
     */
    public function get_private_config()
    {
        if (isset($this->private_config)) {
            return $this->private_config;
        }

        require_once 'model/fs_var.php';
        $fs_var = new fs_var();

        $this->private_config = [
            'github_token' => '',
            'private_plugins_url' => '',
            'enabled' => false
        ];

        $saved_config = $fs_var->simple_get('private_plugins_config');
        if ($saved_config) {
            $decoded = json_decode($saved_config, true);
            if (is_array($decoded)) {
                $this->private_config = array_merge($this->private_config, $decoded);
            }
        }

        return $this->private_config;
    }

    /**
     * Guarda la configuración de plugins privados.
     * @param string $github_token Token de acceso de GitHub
     * @param string $private_plugins_url URL del JSON de plugins privados
     * @return bool
     */
    public function save_private_config($github_token, $private_plugins_url)
    {
        require_once 'model/fs_var.php';
        $fs_var = new fs_var();

        $this->private_config = [
            'github_token' => $github_token,
            'private_plugins_url' => $private_plugins_url,
            'enabled' => !empty($github_token) && !empty($private_plugins_url)
        ];

        $result = $fs_var->simple_save('private_plugins_config', json_encode($this->private_config));

        // Limpiar cache de la lista de plugins privados
        $this->cache->delete('private_download_list');

        return $result;
    }

    /**
     * Elimina la configuración de plugins privados.
     * @return bool
     */
    public function delete_private_config()
    {
        require_once 'model/fs_var.php';
        $fs_var = new fs_var();

        $this->private_config = null;
        $this->private_download_list = null;

        // Limpiar cache
        $this->cache->delete('private_download_list');

        return $fs_var->simple_delete('private_plugins_config');
    }

    /**
     * Verifica si la configuración de plugins privados está activa.
     * @return bool
     */
    public function is_private_plugins_enabled()
    {
        $config = $this->get_private_config();
        return $config['enabled'] && !empty($config['github_token']) && !empty($config['private_plugins_url']);
    }

    /**
     * Devuelve la lista de plugins privados disponibles para descarga.
     * @param bool $force_reload Forzar recarga ignorando caché
     * @return array
     */
    public function private_downloads($force_reload = false)
    {
        if (!$force_reload && isset($this->private_download_list) && !empty($this->private_download_list)) {
            return $this->private_download_list;
        }

        // Verificar si está configurado
        if (!$this->is_private_plugins_enabled()) {
            $this->private_download_list = [];
            return $this->private_download_list;
        }

        // Buscar en la cache (solo si no se fuerza recarga)
        if (!$force_reload) {
            $cached = $this->cache->get('private_download_list');
            // Verificar que la caché tenga datos válidos (con versión o descripción)
            if ($cached !== false && is_array($cached) && !empty($cached)) {
                // Verificar si al menos el primer plugin tiene datos del INI
                $first_plugin = reset($cached);
                if (isset($first_plugin['version']) || isset($first_plugin['descripcion'])) {
                    $this->private_download_list = $cached;
                    return $this->private_download_list;
                }
                // Si la caché no tiene datos del INI, invalidarla
                $this->cache->delete('private_download_list');
            }
        }

        $config = $this->get_private_config();

        // Descargar la lista de plugins privados usando autenticación
        $json = @fs_file_get_contents_auth($config['private_plugins_url'], $config['github_token'], 15);

        if ($json && $json != 'ERROR') {
            $this->private_download_list = json_decode($json, true);

            if (!is_array($this->private_download_list)) {
                $this->core_log->new_error('Error al parsear el JSON de plugins privados. Verifica el formato del archivo.');
                $this->private_download_list = [];
                return $this->private_download_list;
            }

            // Marcar cada plugin con el flag de instalado y como privado
            // Y obtener versión/descripción del fsframework.ini del repositorio
            foreach ($this->private_download_list as $key => $value) {
                $this->private_download_list[$key]['instalado'] = file_exists(FS_FOLDER . '/plugins/' . $value['nombre']);
                $this->private_download_list[$key]['privado'] = true;

                // Asegurar que tiene un ID único (prefijado para evitar colisiones)
                if (!isset($this->private_download_list[$key]['id'])) {
                    $this->private_download_list[$key]['id'] = 'priv_' . $key;
                } else {
                    $this->private_download_list[$key]['id'] = 'priv_' . $this->private_download_list[$key]['id'];
                }

                // Obtener datos del fsframework.ini del repositorio remoto
                $remote_ini_data = $this->get_remote_plugin_ini($value, $config['github_token']);
                if ($remote_ini_data) {
                    // Sobrescribir versión y descripción con los datos del ini
                    if (isset($remote_ini_data['version'])) {
                        $this->private_download_list[$key]['version'] = $remote_ini_data['version'];
                    }
                    if (isset($remote_ini_data['description'])) {
                        $this->private_download_list[$key]['descripcion'] = $remote_ini_data['description'];
                    }
                    if (isset($remote_ini_data['min_version'])) {
                        $this->private_download_list[$key]['min_version'] = $remote_ini_data['min_version'];
                    }
                    if (isset($remote_ini_data['require'])) {
                        $this->private_download_list[$key]['require'] = $remote_ini_data['require'];
                    }
                }
            }

            $this->cache->set('private_download_list', $this->private_download_list, 3600); // Cache por 1 hora
            return $this->private_download_list;
        }

        $this->core_log->new_error('Error al descargar la lista de plugins privados. Verifica el token y la URL.');
        $this->private_download_list = [];
        return $this->private_download_list;
    }

    /**
     * Obtiene los datos del fsframework.ini de un repositorio remoto.
     * @param array $plugin_data Datos del plugin del JSON
     * @param string $token Token de GitHub
     * @return array|false Array con los datos del ini o false si falla
     */
    private function get_remote_plugin_ini($plugin_data, $token)
    {
        // Construir la URL del fsframework.ini basándose en el link del repositorio
        // Formato esperado del link: https://github.com/usuario/repo
        if (!isset($plugin_data['link']) || empty($plugin_data['link'])) {
            return false;
        }

        // Extraer usuario y repo del link
        $parsed = parse_url($plugin_data['link']);
        if (!isset($parsed['path'])) {
            return false;
        }

        $path_parts = explode('/', trim($parsed['path'], '/'));
        if (count($path_parts) < 2) {
            return false;
        }

        $user = $path_parts[0];
        $repo = $path_parts[1];

        // Determinar la rama (por defecto master, pero podría ser main)
        $branch = isset($plugin_data['branch']) ? $plugin_data['branch'] : 'master';

        // Intentar con fsframework.ini primero, luego facturascripts.ini
        // Usamos la API de GitHub para repositorios privados
        $ini_files = ['fsframework.ini', 'facturascripts.ini'];

        foreach ($ini_files as $ini_file) {
            // Usar la API de GitHub para obtener el contenido del archivo
            $api_url = "https://api.github.com/repos/{$user}/{$repo}/contents/{$ini_file}?ref={$branch}";
            $ini_content = @fs_file_get_contents_github_api($api_url, $token, 10);

            if ($ini_content && $ini_content != 'ERROR') {
                // Intentar parsear con secciones primero
                $ini_data = @parse_ini_string($ini_content, true);

                if ($ini_data && is_array($ini_data)) {
                    // Caso 1: Tiene la sección [plugin]
                    if (isset($ini_data['plugin']) && is_array($ini_data['plugin'])) {
                        return $ini_data['plugin'];
                    }

                    // Caso 2: Sin secciones - verificar si tiene claves típicas del ini
                    // (version, description, name, min_version)
                    if (isset($ini_data['version']) || isset($ini_data['description']) || isset($ini_data['name'])) {
                        return $ini_data;
                    }

                    // Caso 3: Puede tener otra sección (ej: [facturascripts])
                    // Buscar la primera sección que contenga datos del plugin
                    foreach ($ini_data as $section => $values) {
                        if (is_array($values) && (isset($values['version']) || isset($values['description']))) {
                            return $values;
                        }
                    }

                    // Si no encontramos sección específica, devolver los datos tal cual
                    return $ini_data;
                }
            }
        }

        return false;
    }

    /**
     * Descarga un plugin privado de la lista.
     * @param string $plugin_id ID del plugin (prefijado con 'priv_')
     * @param bool $create_backup Crear backup antes de sobrescribir
     * @return bool
     */
    public function download_private($plugin_id, $create_backup = true)
    {
        if ($this->disable_mod_plugins) {
            $this->core_log->new_error('No tienes permiso para descargar plugins.');
            return false;
        }

        if (!$this->is_private_plugins_enabled()) {
            $this->core_log->new_error('La descarga de plugins privados no está configurada.');
            return false;
        }

        $config = $this->get_private_config();

        foreach ($this->private_downloads() as $item) {
            if ($item['id'] != $plugin_id) {
                continue;
            }

            $this->core_log->new_message('Descargando el plugin privado ' . $item['nombre']);

            // Descargar usando autenticación
            if (!@fs_file_download_auth($item['zip_link'], FS_FOLDER . '/download.zip', $config['github_token'], 60)) {
                $this->core_log->new_error('Error al descargar el plugin privado. Verifica el token y los permisos del repositorio.');
                return false;
            }

            // SIEMPRE crear backup si el plugin ya existe (para plugins privados)
            if (file_exists(FS_FOLDER . '/plugins/' . $item['nombre'])) {
                if (!$this->create_backup($item['nombre'])) {
                    unlink(FS_FOLDER . '/download.zip');
                    return false;
                }
            }

            $plugins_list = fs_file_manager::scan_folder(FS_FOLDER . '/plugins');

            if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/download.zip', FS_FOLDER . '/plugins/')) {
                $this->core_log->new_error('Error al extraer el ZIP. Código de integridad o seguridad fallido.');
                unlink(FS_FOLDER . '/download.zip');
                return false;
            }
            unlink(FS_FOLDER . '/download.zip');

            // Renombrar si es necesario
            foreach (fs_file_manager::scan_folder(FS_FOLDER . '/plugins') as $f) {
                if (is_dir(FS_FOLDER . '/plugins/' . $f) && !in_array($f, $plugins_list)) {
                    // Eliminar el plugin existente si hay que sobrescribir
                    if (file_exists(FS_FOLDER . '/plugins/' . $item['nombre'])) {
                        fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $item['nombre']);
                    }
                    rename(FS_FOLDER . '/plugins/' . $f, FS_FOLDER . '/plugins/' . $item['nombre']);
                    break;
                }
            }

            $this->core_log->new_message('Plugin privado añadido correctamente.');
            return $this->enable($item['nombre']);
        }

        $this->core_log->new_error('Plugin privado no encontrado en la lista.');
        return false;
    }

    /**
     * Prueba la conexión con los plugins privados.
     * @return array Array con 'success' (bool) y 'message' (string)
     */
    public function test_private_connection()
    {
        $config = $this->get_private_config();

        if (empty($config['github_token']) || empty($config['private_plugins_url'])) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta. Debes proporcionar el token y la URL.'
            ];
        }

        // Intentar descargar el JSON
        $json = @fs_file_get_contents_auth($config['private_plugins_url'], $config['github_token'], 10);

        if (!$json || $json == 'ERROR') {
            return [
                'success' => false,
                'message' => 'No se pudo conectar. Verifica el token y la URL del JSON.'
            ];
        }

        $plugins = json_decode($json, true);
        if (!is_array($plugins)) {
            return [
                'success' => false,
                'message' => 'El archivo JSON no tiene un formato válido.'
            ];
        }

        // Probar lectura del ini del primer plugin
        $ini_test = '';
        if (count($plugins) > 0 && isset($plugins[0]['link'])) {
            $ini_data = $this->get_remote_plugin_ini($plugins[0], $config['github_token']);
            if ($ini_data) {
                $ini_test = ' | INI leído correctamente: v' . (isset($ini_data['version']) ? $ini_data['version'] : '?');
            } else {
                $ini_test = ' | Error al leer fsframework.ini del primer plugin';
            }
        }

        return [
            'success' => true,
            'message' => 'Conexión exitosa. Se encontraron ' . count($plugins) . ' plugin(s) disponible(s).' . $ini_test
        ];
    }

    /**
     * Método de depuración para probar la lectura del ini remoto.
     * @param string $plugin_name Nombre del plugin a probar
     * @return array Resultado del debug
     */
    public function debug_remote_ini($plugin_name = null)
    {
        $config = $this->get_private_config();
        $result = [
            'token_presente' => !empty($config['github_token']),
            'token_length' => strlen($config['github_token']),
            'plugins' => []
        ];

        // Forzar recarga de la lista (sin cache)
        $this->cache->delete('private_download_list');
        $json = @fs_file_get_contents_auth($config['private_plugins_url'], $config['github_token'], 10);

        if (!$json || $json == 'ERROR') {
            $result['error'] = 'No se pudo descargar el JSON';
            return $result;
        }

        $plugins = json_decode($json, true);
        if (!is_array($plugins)) {
            $result['error'] = 'JSON inválido';
            return $result;
        }

        foreach ($plugins as $plugin) {
            if ($plugin_name && $plugin['nombre'] !== $plugin_name) {
                continue;
            }

            $plugin_debug = [
                'nombre' => $plugin['nombre'],
                'link' => isset($plugin['link']) ? $plugin['link'] : 'NO DEFINIDO',
                'branch' => isset($plugin['branch']) ? $plugin['branch'] : 'master (default)',
            ];

            // Construir URL de la API
            if (isset($plugin['link'])) {
                $parsed = parse_url($plugin['link']);
                $path_parts = explode('/', trim($parsed['path'], '/'));
                $user = $path_parts[0];
                $repo = $path_parts[1];
                $branch = isset($plugin['branch']) ? $plugin['branch'] : 'master';

                $api_url = "https://api.github.com/repos/{$user}/{$repo}/contents/fsframework.ini?ref={$branch}";
                $plugin_debug['api_url'] = $api_url;

                // Intentar obtener el contenido
                $ini_content = @fs_file_get_contents_github_api($api_url, $config['github_token'], 10);
                $plugin_debug['ini_response'] = ($ini_content && $ini_content != 'ERROR') ? substr($ini_content, 0, 200) : 'ERROR o vacío';

                if ($ini_content && $ini_content != 'ERROR') {
                    $ini_data = @parse_ini_string($ini_content, true);
                    $plugin_debug['ini_parsed'] = $ini_data;
                }
            }

            $result['plugins'][] = $plugin_debug;
        }

        return $result;
    }

    /**
     * Refresca la cache de plugins privados.
     * @return bool
     */
    public function refresh_private_downloads()
    {
        // Eliminar la caché de la lista de plugins privados
        $this->cache->delete('private_download_list');
        // Resetear la variable interna para forzar recarga
        $this->private_download_list = null;
        // Forzar recarga inmediata con los datos del INI
        $this->private_downloads(true);
        return true;
    }

    public function enable($plugin_name)
    {
        if (in_array($plugin_name, $GLOBALS['plugins'])) {
            $this->core_log->new_message('Plugin <b>' . $plugin_name . '</b> ya activado.');
            return true;
        }

        $name = $this->rename_plugin($plugin_name);

        /// comprobamos las dependencias
        $install = TRUE;
        $wizard = FALSE;
        foreach ($this->installed() as $pitem) {
            if ($pitem['name'] != $name) {
                continue;
            }

            $wizard = $pitem['wizard'];
            foreach ($pitem['require'] as $req) {
                if (in_array($req, $GLOBALS['plugins'])) {
                    continue;
                }

                $install = FALSE;
                $txt = 'Dependencias incumplidas: <b>' . $req . '</b>';
                foreach ($this->downloads() as $value) {
                    if ($value['nombre'] == $req && !$this->disable_add_plugins) {
                        $txt .= '. Puedes descargar este plugin desde la <b>pestaña descargas</b>.';
                        break;
                    }
                }

                $this->core_log->new_error($txt);
            }
            break;
        }

        if (!$install) {
            $this->core_log->new_error('Imposible activar el plugin <b>' . $name . '</b>.');
            return false;
        }

        /// Añadimos el plugin al final de la lista para respetar el orden de dependencias
        /// Los plugins dependientes deben cargarse después de sus dependencias
        $GLOBALS['plugins'][] = $name;
        if (!$this->save()) {
            $this->core_log->new_error('Imposible activar el plugin <b>' . $name . '</b>.');
            return false;
        }

        require_all_models();

        if ($wizard) {
            $this->core_log->new_advice('Ya puedes <a href="index.php?page=' . $wizard . '">configurar el plugin</a>.');
            header('Location: index.php?page=' . $wizard);
            $this->clean_cache();
            return true;
        }

        $this->enable_plugin_controllers($name);
        $this->core_log->new_message('Plugin <b>' . $name . '</b> activado correctamente.');
        $this->core_log->save('Plugin ' . $name . ' activado correctamente.', 'msg');
        $this->clean_cache();
        return true;
    }

    public function enabled()
    {
        return $GLOBALS['plugins'];
    }

    /**
     * Check if a specific plugin is enabled.
     * 
     * @param string $pluginName The plugin name to check.
     * @return bool True if the plugin is enabled, false otherwise.
     */
    public function is_plugin_enabled($pluginName)
    {
        return in_array($pluginName, $this->enabled());
    }

    public function install($path, $name, $create_backup = false)
    {
        if ($this->disable_add_plugins) {
            $this->core_log->new_error('La subida de plugins está desactivada. Contacta con tu proveedor de hosting.');
            return false;
        }

        // Extraer temporalmente para detectar el nombre real del plugin
        $temp_dir = FS_FOLDER . '/tmp/plugin_upload_temp/';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }

        if (!fs_file_manager::extract_zip_safe($path, $temp_dir)) {
            $this->core_log->new_error('Error al extraer el archivo ZIP.');
            return false;
        }

        // Detectar el nombre del plugin extraído
        $extracted_folders = [];
        foreach (fs_file_manager::scan_folder($temp_dir) as $f) {
            if (is_dir($temp_dir . $f)) {
                $extracted_folders[] = $f;
            }
        }

        if (empty($extracted_folders)) {
            fs_file_manager::del_tree($temp_dir);
            $this->core_log->new_error('El archivo ZIP no contiene ninguna carpeta de plugin válida.');
            return false;
        }

        $plugin_folder_name = $extracted_folders[0];
        $plugin_name = $this->rename_plugin($plugin_folder_name);

        // Si el plugin ya existe y se solicita backup, crearlo
        if ($create_backup && file_exists(FS_FOLDER . '/plugins/' . $plugin_name)) {
            if (!$this->create_backup($plugin_name)) {
                fs_file_manager::del_tree($temp_dir);
                return false;
            }
        }

        // Mover el plugin de la carpeta temporal a plugins/
        $source = $temp_dir . $plugin_folder_name;
        $destination = FS_FOLDER . '/plugins/' . $plugin_name;

        // Si existe, eliminarlo primero
        if (file_exists($destination)) {
            fs_file_manager::del_tree($destination);
        }

        // Mover la carpeta
        if (!rename($source, $destination)) {
            fs_file_manager::del_tree($temp_dir);
            $this->core_log->new_error('Error al mover el plugin a la carpeta de plugins.');
            return false;
        }

        // Limpiar carpeta temporal
        fs_file_manager::del_tree($temp_dir);

        $this->core_log->new_message('Plugin <b>' . $plugin_name . '</b> añadido correctamente. Ya puede activarlo.');
        $this->clean_cache();
        return $plugin_name;
    }

    public function installed()
    {
        $plugins = [];
        $disabled = $this->disabled();

        foreach (fs_file_manager::scan_folder(FS_FOLDER . '/plugins') as $file_name) {
            // Filtrar carpetas que terminen en _back
            if (
                !is_dir(FS_FOLDER . '/plugins/' . $file_name) ||
                in_array($file_name, $disabled) ||
                substr($file_name, -5) === '_back'
            ) {
                continue;
            }

            $plugin_data = $this->get_plugin_data($file_name);
            // Agregar flag has_backup si existe versión backup
            $plugin_data['has_backup'] = $this->has_backup($file_name);
            $plugins[] = $plugin_data;
        }

        return $plugins;
    }

    public function remove($plugin_name)
    {
        if ($this->disable_rm_plugins) {
            $this->core_log->new_error('No tienes permiso para eliminar plugins.');
            return false;
        }

        if (!is_writable(FS_FOLDER . '/plugins/' . $plugin_name)) {
            $this->core_log->new_error('No tienes permisos de escritura sobre la carpeta plugins/' . $plugin_name);
            return false;
        }

        if (fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $plugin_name)) {
            $this->core_log->new_message('Plugin ' . $plugin_name . ' eliminado correctamente.');
            $this->core_log->save('Plugin ' . $plugin_name . ' eliminado correctamente.');
            $this->clean_cache();
            return true;
        }

        $this->core_log->new_error('Imposible eliminar el plugin ' . $plugin_name);
        return false;
    }

    private function clean_cache()
    {
        $this->cache->clean();
        fs_file_manager::clear_raintpl_cache();
    }

    private function disable_unnused_pages()
    {
        $eliminadas = [];
        $page_model = new fs_page();
        foreach ($page_model->all() as $page) {
            if (file_exists(FS_FOLDER . '/controller/' . $page->name . '.php')) {
                continue;
            }

            $encontrada = FALSE;
            foreach ($this->enabled() as $plugin) {
                if (file_exists(FS_FOLDER . '/plugins/' . $plugin . '/controller/' . $page->name . '.php')) {
                    $encontrada = TRUE;
                    break;
                }
            }

            if (!$encontrada && $page->delete()) {
                $eliminadas[] = $page->name;
            }
        }

        if (!empty($eliminadas)) {
            $this->core_log->new_message('Se han eliminado automáticamente las siguientes páginas: ' . implode(', ', $eliminadas));
        }
    }

    private function enable_plugin_controllers($plugin_name)
    {
        /// cargamos el archivo functions.php
        if (file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '/functions.php')) {
            require_once 'plugins/' . $plugin_name . '/functions.php';
        }

        /// buscamos controladores clásicos
        $page_list = [];
        if (file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '/controller')) {
            foreach (fs_file_manager::scan_files(FS_FOLDER . '/plugins/' . $plugin_name . '/controller', 'php') as $f) {
                $page_name = substr($f, 0, -4);
                require_once 'plugins/' . $plugin_name . '/controller/' . $f;

                if (class_exists($page_name)) {
                    $page_list[] = $page_name;
                    $new_fsc = new $page_name();
                    if (!$new_fsc->page->save()) {
                        $this->core_log->new_error("Imposible guardar la página " . $page_name);
                    }
                    unset($new_fsc);
                }
            }
        }

        /// buscamos controladores modernos
        if (file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '/Controller')) {
            foreach (fs_file_manager::scan_files(FS_FOLDER . '/plugins/' . $plugin_name . '/Controller', 'php') as $f) {
                $short_name = substr($f, 0, -4);
                $full_class = "FacturaScripts\\Plugins\\$plugin_name\\Controller\\$short_name";

                if (class_exists($full_class)) {
                    $page_list[] = $short_name;
                    $new_fsc = new $full_class();

                    // Si el controlador moderno tiene la propiedad legacy 'page' (vía Base\Controller)
                    if (isset($new_fsc->page) && $new_fsc->page instanceof fs_page) {
                        if (!$new_fsc->page->save()) {
                            $this->core_log->new_error("Imposible guardar la página moderna " . $short_name);
                        }
                    } else {
                        // Si no hereda de Base\Controller, creamos una entrada básica
                        $page = new fs_page();
                        $page->name = $short_name;
                        $page->title = $short_name;
                        $page->folder = 'new';
                        if (!$page->save()) {
                            $this->core_log->new_error("Imposible guardar la página moderna básica " . $short_name);
                        }
                    }
                    unset($new_fsc);

                    // Asegurar permisos para administradores
                    if (class_exists('fs_access')) {
                        // Re-instanciar para obtener el nombre real de la página
                        $temp_fsc = new $full_class();
                        // Obtener nombre de página: prioridad a getPageData, luego objeto page, luego short_name
                        $realPageName = $short_name;
                        if (method_exists($temp_fsc, 'getPageData')) {
                            $pd = $temp_fsc->getPageData();
                            if (!empty($pd['name'])) {
                                $realPageName = $pd['name'];
                            }
                        } elseif (isset($temp_fsc->page) && !empty($temp_fsc->page->name)) {
                            $realPageName = $temp_fsc->page->name;
                        }

                        $access = new \fs_access();
                        $access->fs_user = 'admin';
                        $access->fs_page = $realPageName;
                        $access->allow_delete = TRUE;
                        $access->save();
                    }
                }
            }
        }

        if (!empty($page_list)) {
            $this->core_log->new_message('Se han activado automáticamente las siguientes páginas: ' . implode(', ', $page_list) . '.');
        }
    }

    private function get_plugin_data($plugin_name)
    {
        $plugin = [
            'compatible' => FALSE,
            'description' => 'Sin descripción.',
            'download2_url' => '',
            'enabled' => FALSE,
            'error_msg' => 'Falta archivo de configuración del plugin',
            'idplugin' => NULL,
            'min_version' => $this->version,
            'name' => $plugin_name,
            'prioridad' => '-',
            'require' => [],
            'update_url' => '',
            'version' => 1,
            'version_url' => '',
            'wizard' => FALSE,
            'legacy_warning' => FALSE,
        ];

        $fsframework_ini = FS_FOLDER . '/plugins/' . $plugin_name . '/fsframework.ini';
        $facturascripts_ini = FS_FOLDER . '/plugins/' . $plugin_name . '/facturascripts.ini';

        // First, try to read fsframework.ini
        if (file_exists($fsframework_ini)) {
            $ini_file = parse_ini_file($fsframework_ini);
            $plugin['error_msg'] = '';
        }
        // If fsframework.ini doesn't exist, try facturascripts.ini
        elseif (file_exists($facturascripts_ini)) {
            $ini_file = parse_ini_file($facturascripts_ini);
        } else {
            return $plugin;
        }

        foreach (['description', 'idplugin', 'min_version', 'update_url', 'version', 'version_url', 'wizard'] as $field) {
            if (isset($ini_file[$field])) {
                $plugin[$field] = $ini_file[$field];
            }
        }

        $plugin['enabled'] = in_array($plugin_name, $this->enabled());
        // Mantener versión como string si tiene formato semántico (x.y.z), sino convertir a int
        if (strpos($plugin['version'], '.') !== false) {
            $plugin['version'] = (string) $plugin['version'];
        } else {
            $plugin['version'] = (int) $plugin['version'];
        }
        $plugin['min_version'] = (float) $plugin['min_version'];

        // Check compatibility based on configuration file type and version
        if (file_exists($fsframework_ini)) {
            // Plugin nativo FSFramework - validar con VERSION principal
            if ($this->version >= $plugin['min_version']) {
                $plugin['compatible'] = true;
            } else {
                $plugin['error_msg'] = 'Requiere FSFramework ' . $plugin['min_version'];
            }
        } else {
            // Plugin de FacturaScripts - detectar arquitectura y requerir plugin de soporte
            if ($plugin['min_version'] >= 2025) {
                // FS2025 - requiere facturascripts_support activo
                if (!in_array('facturascripts_support', $GLOBALS['plugins'])) {
                    $plugin['compatible'] = false;
                    $plugin['requires_support'] = 'facturascripts_support';
                    $plugin['error_msg'] = 'Plugin de FacturaScripts 2025. Requiere activar el plugin <b>facturascripts_support</b> primero.';
                } else {
                    // Delegar validación de versión al plugin facturascripts_support
                    $plugin['compatible'] = $this->validate_fs2025_compatibility($plugin['min_version']);
                    $plugin['legacy_warning'] = true;
                    if ($plugin['compatible']) {
                        $plugin['error_msg'] = 'Plugin de FacturaScripts 2025. Aunque se ha mantenido la compatibilidad, no se garantiza al 100%. Se recomienda probarlo en un entorno de pruebas antes de usarlo en producción.';
                    } else {
                        $plugin['error_msg'] = 'Requiere FacturaScripts ' . $plugin['min_version'] . '. Versión soportada insuficiente.';
                    }
                }
            } else {
                // FS2017 - requiere legacy_support activo
                if (!in_array('legacy_support', $GLOBALS['plugins'])) {
                    $plugin['compatible'] = false;
                    $plugin['requires_support'] = 'legacy_support';
                    $plugin['error_msg'] = 'Plugin de FacturaScripts 2017. Requiere activar el plugin <b>legacy_support</b> primero.';
                } else {
                    // Delegar validación de versión al plugin legacy_support
                    $plugin['compatible'] = $this->validate_fs2017_compatibility($plugin['min_version']);
                    $plugin['legacy_warning'] = true;
                    if ($plugin['compatible']) {
                        $plugin['error_msg'] = 'Plugin de FacturaScripts 2017 (arquitectura antigua). Aunque se ha mantenido la compatibilidad, no se garantiza al 100%. Se recomienda probarlo en un entorno de pruebas antes de usarlo en producción.';
                    } else {
                        $plugin['error_msg'] = 'Requiere FacturaScripts ' . $plugin['min_version'] . '. Versión soportada insuficiente.';
                    }
                }
            }
        }

        if (file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '/description')) {
            $plugin['description'] = file_get_contents(FS_FOLDER . '/plugins/' . $plugin_name . '/description');
        }

        if (isset($ini_file['require']) && $ini_file['require'] != '') {
            $plugin['require'] = explode(',', $ini_file['require']);
        }

        if (!isset($ini_file['version_url']) && $this->downloads()) {
            foreach ($this->downloads() as $ditem) {
                if ($ditem['id'] != $plugin['idplugin']) {
                    continue;
                }

                if (intval($ditem['version']) > $plugin['version']) {
                    $plugin['download2_url'] = 'updater.php?idplugin=' . $plugin['idplugin'] . '&name=' . $plugin_name;
                }
                break;
            }
        }

        if ($plugin['enabled']) {
            foreach (array_reverse($this->enabled()) as $i => $value) {
                if ($value == $plugin_name) {
                    $plugin['prioridad'] = $i;
                    break;
                }
            }
        }

        return $plugin;
    }

    private function rename_plugin($name)
    {
        $new_name = $name;
        if (strpos($name, '-master') !== FALSE) {
            /// renombramos el directorio
            $new_name = substr($name, 0, strpos($name, '-master'));
            if (!rename(FS_FOLDER . '/plugins/' . $name, FS_FOLDER . '/plugins/' . $new_name)) {
                $this->core_log->new_error('Error al renombrar el plugin.');
            }
        }

        return $new_name;
    }

    private function save()
    {
        if (empty($GLOBALS['plugins'])) {
            return unlink(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list');
        }

        $string = implode(',', $GLOBALS['plugins']);
        if (false === file_put_contents(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list', $string)) {
            return false;
        }

        return true;
    }

    /**
     * Verifica si existe un backup para un plugin
     *
     * @param string $plugin_name
     * @return bool
     */
    public function has_backup($plugin_name)
    {
        return file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '_back') &&
            is_dir(FS_FOLDER . '/plugins/' . $plugin_name . '_back');
    }

    /**
     * Crea un backup del plugin actual
     *
     * @param string $plugin_name
     * @return bool
     */
    public function create_backup($plugin_name)
    {
        $plugin_path = FS_FOLDER . '/plugins/' . $plugin_name;
        $backup_path = FS_FOLDER . '/plugins/' . $plugin_name . '_back';

        if (!file_exists($plugin_path) || !is_dir($plugin_path)) {
            $this->core_log->new_error('El plugin ' . $plugin_name . ' no existe.');
            return false;
        }

        if (!is_writable(FS_FOLDER . '/plugins/')) {
            $this->core_log->new_error('No tienes permisos de escritura sobre la carpeta plugins/');
            return false;
        }

        // Si ya existe un backup, eliminarlo
        if ($this->has_backup($plugin_name)) {
            if (!fs_file_manager::del_tree($backup_path)) {
                $this->core_log->new_error('Error al eliminar el backup anterior de ' . $plugin_name);
                return false;
            }
        }

        // Crear el backup copiando la carpeta
        if (!fs_file_manager::recurse_copy($plugin_path, $backup_path)) {
            $this->core_log->new_error('Error al crear el backup de ' . $plugin_name);
            return false;
        }

        $this->core_log->new_message('Backup del plugin <b>' . $plugin_name . '</b> creado correctamente.');
        return true;
    }

    /**
     * Restaura un plugin desde su backup
     *
     * @param string $plugin_name
     * @return bool
     */
    public function restore_backup($plugin_name)
    {
        $plugin_path = FS_FOLDER . '/plugins/' . $plugin_name;
        $backup_path = FS_FOLDER . '/plugins/' . $plugin_name . '_back';

        if (!$this->has_backup($plugin_name)) {
            $this->core_log->new_error('No existe backup para el plugin ' . $plugin_name);
            return false;
        }

        if (!is_writable(FS_FOLDER . '/plugins/')) {
            $this->core_log->new_error('No tienes permisos de escritura sobre la carpeta plugins/');
            return false;
        }

        // Eliminar el plugin actual
        if (file_exists($plugin_path)) {
            if (!fs_file_manager::del_tree($plugin_path)) {
                $this->core_log->new_error('Error al eliminar el plugin actual ' . $plugin_name);
                return false;
            }
        }

        // Renombrar el backup como el plugin principal
        if (!rename($backup_path, $plugin_path)) {
            $this->core_log->new_error('Error al restaurar el backup de ' . $plugin_name);
            return false;
        }

        $this->core_log->new_message('Plugin <b>' . $plugin_name . '</b> restaurado correctamente desde el backup.');
        $this->clean_cache();
        return true;
    }

    /**
     * Verifica si un plugin existe y obtiene su información
     *
     * @param string $plugin_name
     * @return array|false Array con info del plugin o false si no existe
     */
    public function check_plugin_exists($plugin_name)
    {
        $plugin_path = FS_FOLDER . '/plugins/' . $plugin_name;

        if (!file_exists($plugin_path) || !is_dir($plugin_path)) {
            return false;
        }

        $plugin_data = $this->get_plugin_data($plugin_name);
        $plugin_data['has_backup'] = $this->has_backup($plugin_name);

        return $plugin_data;
    }

    /**
     * Detecta el nombre del plugin desde un archivo ZIP
     *
     * @param string $zip_path Ruta al archivo ZIP
     * @return array|false Array con 'name' y 'version' o false si hay error
     */
    public function detect_plugin_from_zip($zip_path)
    {
        // Crear carpeta temporal
        $temp_dir = FS_FOLDER . '/tmp/plugin_detect_temp/';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        } else {
            // Limpiar si ya existe
            fs_file_manager::del_tree($temp_dir);
            mkdir($temp_dir, 0777, true);
        }

        if (!fs_file_manager::extract_zip_safe($zip_path, $temp_dir)) {
            return false;
        }

        // Detectar carpeta del plugin
        $plugin_folder = null;
        foreach (fs_file_manager::scan_folder($temp_dir) as $f) {
            if (is_dir($temp_dir . $f)) {
                $plugin_folder = $f;
                break;
            }
        }

        if (!$plugin_folder) {
            fs_file_manager::del_tree($temp_dir);
            return false;
        }

        $plugin_name = $this->rename_plugin($plugin_folder);

        // Intentar leer la versión del archivo ini
        $version = 1;
        $fsframework_ini = $temp_dir . $plugin_folder . '/fsframework.ini';
        $facturascripts_ini = $temp_dir . $plugin_folder . '/facturascripts.ini';

        if (file_exists($fsframework_ini)) {
            $ini_data = parse_ini_file($fsframework_ini);
            if (isset($ini_data['version'])) {
                $version = (int) $ini_data['version'];
            }
        } elseif (file_exists($facturascripts_ini)) {
            $ini_data = parse_ini_file($facturascripts_ini);
            if (isset($ini_data['version'])) {
                $version = (int) $ini_data['version'];
            }
        }

        // Limpiar carpeta temporal
        fs_file_manager::del_tree($temp_dir);

        return [
            'name' => $plugin_name,
            'version' => $version
        ];
    }

    /**
     * Valida la compatibilidad de un plugin FS2017 delegando al plugin legacy_support.
     * 
     * @param float $min_version Versión mínima requerida por el plugin
     * @return bool True si es compatible, false en caso contrario
     */
    private function validate_fs2017_compatibility($min_version)
    {
        // Intentar usar el validador del plugin legacy_support
        $validator_class = 'FacturaScripts\\Plugins\\legacy_support\\VersionValidator';
        if (class_exists($validator_class)) {
            return $validator_class::isCompatible($min_version);
        }

        // Fallback: cargar el archivo directamente si el autoloader no lo ha cargado
        $validator_file = FS_FOLDER . '/plugins/legacy_support/VersionValidator.php';
        if (file_exists($validator_file)) {
            require_once $validator_file;
            if (class_exists($validator_class)) {
                return $validator_class::isCompatible($min_version);
            }
        }

        // Si no existe el validador, intentar leer VERSION-FS2017 del plugin
        $version_file = FS_FOLDER . '/plugins/legacy_support/VERSION-FS2017';
        if (file_exists($version_file)) {
            $version = (float) trim(file_get_contents($version_file));
            return $version >= $min_version;
        }

        // Fallback final: asumir compatible si el plugin de soporte está activo
        return true;
    }

    /**
     * Valida la compatibilidad de un plugin FS2025 delegando al plugin facturascripts_support.
     * 
     * @param float $min_version Versión mínima requerida por el plugin
     * @return bool True si es compatible, false en caso contrario
     */
    private function validate_fs2025_compatibility($min_version)
    {
        // Intentar usar el validador del plugin facturascripts_support
        $validator_class = 'FacturaScripts\\Plugins\\facturascripts_support\\VersionValidator';
        if (class_exists($validator_class)) {
            return $validator_class::isCompatible($min_version);
        }

        // Fallback: cargar el archivo directamente si el autoloader no lo ha cargado
        $validator_file = FS_FOLDER . '/plugins/facturascripts_support/VersionValidator.php';
        if (file_exists($validator_file)) {
            require_once $validator_file;
            if (class_exists($validator_class)) {
                return $validator_class::isCompatible($min_version);
            }
        }

        // Si no existe el validador, intentar leer VERSION-FS2025 del plugin
        $version_file = FS_FOLDER . '/plugins/facturascripts_support/VERSION-FS2025';
        if (file_exists($version_file)) {
            $version = (float) trim(file_get_contents($version_file));
            return $version >= $min_version;
        }

        // Fallback final: asumir compatible si el plugin de soporte está activo
        return true;
    }
}
