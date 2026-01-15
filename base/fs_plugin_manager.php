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
     *
     * @var float
     */
    public $version = 2017.901;

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

        if (file_exists('VERSION')) {
            $this->version = (float) trim(file_get_contents(FS_FOLDER . '/VERSION'));
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

        $this->clean_cache();
        return true;
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

            $zip = new ZipArchive();
            $res = $zip->open(FS_FOLDER . '/download.zip', ZipArchive::CHECKCONS);
            if ($res !== TRUE) {
                $this->core_log->new_error('Error al abrir el ZIP. Código: ' . $res);
                return false;
            }

            // Crear backup si existe y se solicita
            if ($create_backup && file_exists(FS_FOLDER . '/plugins/' . $item['nombre'])) {
                if (!$this->create_backup($item['nombre'])) {
                    $zip->close();
                    unlink(FS_FOLDER . '/download.zip');
                    return false;
                }
            }

            $plugins_list = fs_file_manager::scan_folder(FS_FOLDER . '/plugins');
            $zip->extractTo(FS_FOLDER . '/plugins/');
            $zip->close();
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

    public function install($path, $name, $create_backup = false)
    {
        if ($this->disable_add_plugins) {
            $this->core_log->new_error('La subida de plugins está desactivada. Contacta con tu proveedor de hosting.');
            return false;
        }

        $zip = new ZipArchive();
        $res = $zip->open($path, ZipArchive::CHECKCONS);
        if ($res !== TRUE) {
            $this->core_log->new_error('Error al abrir el archivo ZIP. Código: ' . $res);
            return false;
        }

        // Extraer temporalmente para detectar el nombre real del plugin
        $temp_dir = FS_FOLDER . '/tmp/plugin_upload_temp/';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }

        $zip->extractTo($temp_dir);
        $zip->close();

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

        /// buscamos controladores
        if (file_exists(FS_FOLDER . '/plugins/' . $plugin_name . '/controller')) {
            $page_list = [];
            foreach (fs_file_manager::scan_files(FS_FOLDER . '/plugins/' . $plugin_name . '/controller', 'php') as $f) {
                $page_name = substr($f, 0, -4);
                $page_list[] = $page_name;

                require_once 'plugins/' . $plugin_name . '/controller/' . $f;
                $new_fsc = new $page_name();

                if (!$new_fsc->page->save()) {
                    $this->core_log->new_error("Imposible guardar la página " . $page_name);
                }

                unset($new_fsc);
            }

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
            $plugin['error_msg'] = 'Falta archivo facturascripts.ini';
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
        $plugin['version'] = (int) $plugin['version'];
        $plugin['min_version'] = (float) $plugin['min_version'];

        // Check compatibility based on configuration file type and version
        if (file_exists($fsframework_ini)) {
            // For fsframework.ini, use standard compatibility check
            if ($this->version >= $plugin['min_version']) {
                $plugin['compatible'] = true;
            } else {
                $plugin['error_msg'] = 'Requiere FSFramework ' . $plugin['min_version'];
            }
        } else {
            // For facturascripts.ini, check if version is greater than 2017.000
            if (2017.901 >= $plugin['min_version']) {
                $plugin['compatible'] = true;
                $plugin['legacy_warning'] = true;
                $plugin['error_msg'] = 'Aunque se ha mantenido la compatibilidad con FacturaScript, no se garantiza la compatiblidad 100%, se recomienda usarlo en un entorno de pruebas y asegurarse que funciona correctamente antes de usarlo en proucción.';
            } else {
                $plugin['compatible'] = false;
                $plugin['error_msg'] = 'Requiere FacturaScripts ' . $plugin['min_version'];
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
        $zip = new ZipArchive();
        $res = $zip->open($zip_path, ZipArchive::CHECKCONS);

        if ($res !== TRUE) {
            return false;
        }

        // Crear carpeta temporal
        $temp_dir = FS_FOLDER . '/tmp/plugin_detect_temp/';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        } else {
            // Limpiar si ya existe
            fs_file_manager::del_tree($temp_dir);
            mkdir($temp_dir, 0777, true);
        }

        $zip->extractTo($temp_dir);
        $zip->close();

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
}
