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
require_once 'base/fs_app.php';
require_once 'base/fs_plugin_manager.php';
require_once 'base/fs_db2.php';

/**
 * Controlador del actualizador de FSFramework.
 * Usa autenticación directa a BD para evitar disparar verificación de tablas.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_updater extends fs_app
{

    /**
     *
     * @var boolean
     */
    public $btn_fin;

    /**
     *
     * @var fs_plugin_manager
     */
    public $plugin_manager;

    /**
     *
     * @var array
     */
    private $plugin_updates;

    /**
     *
     * @var array
     */
    public $plugins;

    /**
     *
     * @var string
     */
    public $tr_options;

    /**
     *
     * @var string
     */
    public $tr_updates;

    /**
     *
     * @var array
     */
    public $updates;

    /**
     *
     * @var string
     */
    public $xid;

    /**
     * Información del usuario autenticado (obtenida por consulta directa).
     * @var array|null
     */
    private $authenticated_user = null;

    public function __construct()
    {
        parent::__construct(__CLASS__);
        $this->btn_fin = FALSE;
        $this->plugin_manager = new fs_plugin_manager(); // Siempre inicializar para evitar null
        $this->plugins = [];
        $this->tr_options = '';
        $this->tr_updates = '';
        $this->xid();

        // Autenticar usando consulta directa a BD (sin instanciar modelos)
        if (!$this->authenticateDirectly()) {
            $this->core_log->new_error('Acceso denegado. Se requieren permisos de administrador.');
            return;
        }

        // Procesar después de autenticación exitosa
        $this->process();
    }

    /**
     * Autentica al usuario usando consulta directa a BD.
     * Evita instanciar modelos que disparan verificación de tablas.
     * @return bool TRUE si el usuario es admin autenticado
     */
    private function authenticateDirectly()
    {
        // Obtener cookies usando Symfony Request si está disponible, o filter_input
        $nick = null;
        $logkey = null;

        try {
            if (class_exists('\\FSFramework\\Core\\Kernel')) {
                $request = \FSFramework\Core\Kernel::request();
                $nick = $request->cookies->get('user');
                $logkey = $request->cookies->get('logkey');
            }
        } catch (\Exception $e) {
            // Fallback si el Kernel no está disponible
        }

        // Fallback a filter_input si Symfony no está disponible
        if ($nick === null) {
            $nick = filter_input(INPUT_COOKIE, 'user');
        }
        if ($logkey === null) {
            $logkey = filter_input(INPUT_COOKIE, 'logkey');
        }

        if (empty($nick) || empty($logkey)) {
            return false;
        }

        // Conectar a BD y verificar usuario directamente
        $db = new fs_db2();
        $db->connect();
        if (!$db->connected()) {
            $this->core_log->new_error('Error de conexión a la base de datos.');
            return false;
        }

        // Consulta directa para obtener usuario (sin usar fs_model)
        $sql = "SELECT nick, log_key, admin, enabled FROM fs_users WHERE nick = " . $this->var2str($nick) . " LIMIT 1;";
        $data = $db->select($sql);

        if (!$data || count($data) === 0) {
            return false;
        }

        $user = $data[0];

        // Verificar log_key
        if ($user['log_key'] !== $logkey) {
            return false;
        }

        // Verificar que está habilitado (compatible con MySQL 0/1 y PostgreSQL t/f)
        if (!$this->toBool($user['enabled'])) {
            return false;
        }

        // Verificar que es admin
        if (!$this->toBool($user['admin'])) {
            return false;
        }

        $this->authenticated_user = $user;
        return true;
    }

    /**
     * Escapa cadenas para uso en SQL (método auxiliar).
     * @param mixed $val
     * @return string
     */
    private function var2str($val)
    {
        if (is_null($val)) {
            return 'NULL';
        }
        $db = new fs_db2();
        return "'" . $db->escape_string($val) . "'";
    }

    /**
     * Convierte valor de BD a boolean (compatible MySQL/PostgreSQL).
     * @param mixed $val
     * @return bool
     */
    private function toBool($val)
    {
        if ($val === true || $val === 1 || $val === '1' || $val === 't' || $val === 'true') {
            return true;
        }
        return false;
    }

    /**
     * Elimina la actualización de la lista de pendientes.
     * @param string $plugin
     */
    private function actualizacion_correcta($plugin = '')
    {
        if (!isset($this->updates)) {
            $this->get_updates();
        }

        if (!$this->updates) {
            return;
        }

        if (empty($plugin)) {
            /// hemos actualizado el core
            $this->updates['core'] = FALSE;
        } else {
            foreach ($this->updates['plugins'] as $i => $pl) {
                if ($pl['name'] == $plugin) {
                    unset($this->updates['plugins'][$i]);
                    break;
                }
            }
        }

        /// guardamos la lista de actualizaciones en cache
        if (count($this->updates['plugins']) > 0) {
            $this->cache->set('updater_lista', $this->updates);
        }
    }

    private function actualizar_nucleo()
    {
        $urls = array(
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/master.zip',
            'https://codeload.github.com/eltictacdicta/fs-framework/zip/refs/heads/master'
        );

        foreach ($urls as $url) {
            if (!@fs_file_download($url, FS_FOLDER . '/update-core.zip')) {
                $this->core_log->new_error('Error al descargar el archivo update-core.zip. Intente de nuevo en unos minutos.');
                continue;
            }

            if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/update-core.zip', FS_FOLDER)) {
                $this->core_log->new_error('Ha habido un error de seguridad o integridad con el archivo update-core.zip.');
                return false;
            }

            /// eliminamos archivos antiguos y hacemos backup de los actuales
            foreach (['base', 'controller', 'extras', 'model', 'raintpl', 'view'] as $folder) {
                fs_file_manager::del_tree(FS_FOLDER . '/' . $folder . '_old/');
                rename(FS_FOLDER . '/' . $folder . '/', FS_FOLDER . '/' . $folder . '_old/');
            }

            /// ahora hay que copiar todos los archivos de fs-framework-master a la raíz y borrar
            fs_file_manager::recurse_copy(FS_FOLDER . '/fs-framework-master/', FS_FOLDER);
            fs_file_manager::del_tree(FS_FOLDER . '/fs-framework-master/');

            $this->core_log->new_message('Actualizado correctamente.');
            $this->actualizacion_correcta('');
            return true;
        }

        return false;
    }

    private function actualizar_plugin($plugin_name)
    {
        foreach ($this->plugin_manager->installed() as $plugin) {
            if ($plugin['name'] != $plugin_name) {
                continue;
            }

            /// descargamos el zip
            if (!@fs_file_download($plugin['update_url'], FS_FOLDER . '/update.zip')) {
                $this->core_log->new_error('Error al descargar el archivo update.zip. Intente de nuevo en unos minutos.');
                return false;
            }

            /// nos guardamos la lista previa de /plugins
            $plugins_list = fs_file_manager::scan_folder(FS_FOLDER . '/plugins');

            /// eliminamos los archivos antiguos
            fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $plugin_name);

            if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/update.zip', FS_FOLDER . '/plugins/')) {
                $this->core_log->new_error('Error al extraer update.zip. Archivo malicioso o corrupto.');
                return false;
            }
            unlink(FS_FOLDER . '/update.zip');

            /// renombramos si es necesario
            foreach (fs_file_manager::scan_folder(FS_FOLDER . '/plugins') as $f) {
                if (is_dir(FS_FOLDER . '/plugins/' . $f) && !in_array($f, $plugins_list)) {
                    rename(FS_FOLDER . '/plugins/' . $f, FS_FOLDER . '/plugins/' . $plugin_name);
                    break;
                }
            }

            $this->core_log->new_message('Plugin actualizado correctamente.');
            $this->actualizacion_correcta($plugin_name);
            return true;
        }

        return false;
    }

    private function actualizar_plugin_pago($idplugin, $name, $key)
    {
        $url = 'https://github.com/eltictacdicta/fs-framework/releases/download/v' . $idplugin . '/fs-framework-' . $idplugin . '.zip';

        /// descargamos el zip
        if (!@fs_file_download($url, FS_FOLDER . '/update-pay.zip')) {
            $this->core_log->new_error('Error al descargar el archivo update-pay.zip. <a href="updater.php?idplugin=' .
                $idplugin . '&name=' . $name . '">¿Clave incorrecta?</a>');
            return false;
        }

        /// eliminamos los archivos antiguos
        fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $name);

        if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/update-pay.zip', FS_FOLDER . '/plugins/')) {
            $this->core_log->new_error('Error al extraer update-pay.zip. Archivo malicioso o corrupto.');
            return false;
        }
        unlink(FS_FOLDER . '/update-pay.zip');

        if (file_exists(FS_FOLDER . '/plugins/' . $name . '-master')) {
            /// renombramos el directorio
            rename(FS_FOLDER . '/plugins/' . $name . '-master', 'plugins/' . $name);
        }

        $this->core_log->new_message('Plugin actualizado correctamente.');
        $this->actualizacion_correcta($name);
        return true;
    }

    public function check_for_plugin_updates()
    {
        if (isset($this->plugin_updates)) {
            return $this->plugin_updates;
        }

        $this->plugin_updates = [];
        foreach ($this->plugin_manager->installed() as $plugin) {
            $this->plugins[] = $plugin['name'];

            if ($plugin['version_url'] != '' && $plugin['update_url'] != '') {
                /// plugin con descarga gratuita
                $internet_ini = @parse_ini_string(@fs_file_get_contents($plugin['version_url']));
                if ($internet_ini && $plugin['version'] < intval($internet_ini['version'])) {
                    $plugin['new_version'] = intval($internet_ini['version']);
                    $plugin['depago'] = FALSE;

                    $this->plugin_updates[] = $plugin;
                }
            } else if ($plugin['idplugin']) {
                /// plugin de pago/oculto
                foreach ($this->plugin_manager->downloads() as $ditem) {
                    if ($ditem['id'] != $plugin['idplugin']) {
                        continue;
                    }

                    if (intval($ditem['version']) > $plugin['version']) {
                        $plugin['new_version'] = intval($ditem['version']);
                        $plugin['depago'] = TRUE;
                        $plugin['private_key'] = '';

                        if (file_exists('tmp/' . FS_TMP_NAME . 'private_keys/' . $plugin['idplugin'])) {
                            $plugin['private_key'] = trim(@file_get_contents('tmp/' . FS_TMP_NAME . 'private_keys/' . $plugin['idplugin']));
                        } else if (!file_exists('tmp/' . FS_TMP_NAME . 'private_keys/') && mkdir('tmp/' . FS_TMP_NAME . 'private_keys/')) {
                            file_put_contents('tmp/' . FS_TMP_NAME . 'private_keys/.htaccess', 'Deny from all');
                        }

                        $this->plugin_updates[] = $plugin;
                    }
                    break;
                }
            }
        }

        return $this->plugin_updates;
    }

    private function comprobar_actualizaciones()
    {
        if (!isset($this->updates)) {
            $this->get_updates();
        }

        if ($this->updates['core']) {
            $this->tr_updates = '<tr>'
                . '<td><b>Núcleo</b></td>'
                . '<td>Núcleo de FSFramework.</td>'
                . '<td class="text-right">' . $this->plugin_manager->version . '</td>'
                . '<td class="text-right"><a href="' . FS_COMMUNITY_URL . '/index.php?page=community_changelog&version='
                . $this->updates['core'] . '" target="_blank">' . $this->updates['core'] . '</a></td>'
                . '<td class="text-right">
                    <a class="btn btn-sm btn-primary" href="updater.php?update=TRUE" role="button">
                        <span class="glyphicon glyphicon-upload" aria-hidden="true"></span>&nbsp; Actualizar
                    </a></td>'
                . '</tr>';
        } else {
            $this->tr_options = '<tr>'
                . '<td><b>Núcleo</b></td>'
                . '<td>Núcleo de FSFramework.</td>'
                . '<td class="text-right">' . $this->plugin_manager->version . '</td>'
                . '<td class="text-right"><a href="' . FS_COMMUNITY_URL . '/index.php?page=community_changelog&version='
                . $this->plugin_manager->version . '" target="_blank">' . $this->plugin_manager->version . '</a></td>'
                . '<td class="text-right">
                    <a class="btn btn-xs btn-default" href="updater.php?reinstall=TRUE" role="button">
                        <span class="glyphicon glyphicon-repeat" aria-hidden="true"></span>&nbsp; Reinstalar
                    </a></td>'
                . '</tr>';

            foreach ($this->updates['plugins'] as $plugin) {
                if (false === $plugin['depago']) {
                    $this->tr_updates .= '<tr>'
                        . '<td>' . $plugin['name'] . '</td>'
                        . '<td>' . $plugin['description'] . '</td>'
                        . '<td class="text-right">' . $plugin['version'] . '</td>'
                        . '<td class="text-right"><a href="' . FS_COMMUNITY_URL . '/index.php?page=community_changelog&version='
                        . $plugin['new_version'] . '&plugin=' . $plugin['name'] . '" target="_blank">' . $plugin['new_version'] . '</a></td>'
                        . '<td class="text-right">'
                        . '<a href="updater.php?plugin=' . $plugin['name'] . '" class="btn btn-xs btn-primary">'
                        . '<span class="glyphicon glyphicon-upload" aria-hidden="true"></span>&nbsp; Actualizar'
                        . '</a>'
                        . '</td></tr>';
                    continue;
                }

                if (!$this->xid) {
                    /// nada
                    continue;
                }

                if (empty($plugin['private_key'])) {
                    $this->tr_updates .= '<tr>'
                        . '<td>' . $plugin['name'] . '</td>'
                        . '<td>' . $plugin['description'] . '</td>'
                        . '<td class="text-right">' . $plugin['version'] . '</td>'
                        . '<td class="text-right"><a href="' . FS_COMMUNITY_URL . '/index.php?page=community_changelog&version='
                        . $plugin['new_version'] . '&plugin=' . $plugin['name'] . '" target="_blank">' . $plugin['new_version'] . '</a></td>'
                        . '<td class="text-right">'
                        . '<div class="btn-group">'
                        . '<a href="#" class="btn btn-xs btn-warning" data-toggle="modal" data-target="#modal_key_' . $plugin['name'] . '">'
                        . '<i class="fa fa-key" aria-hidden="true"></i>&nbsp; Añadir clave'
                        . '</a>'
                        . '</div>'
                        . '</td></tr>';
                    continue;
                }

                $this->tr_updates .= '<tr>'
                    . '<td>' . $plugin['name'] . '</td>'
                    . '<td>' . $plugin['description'] . '</td>'
                    . '<td class="text-right">' . $plugin['version'] . '</td>'
                    . '<td class="text-right"><a href="' . FS_COMMUNITY_URL . '/index.php?page=community_changelog&version='
                    . $plugin['new_version'] . '&plugin=' . $plugin['name'] . '" target="_blank">' . $plugin['new_version'] . '</a></td>'
                    . '<td class="text-center">'
                    . '<div class="btn-group">'
                    . '<a href="updater.php?idplugin=' . $plugin['idplugin'] . '&name=' . $plugin['name'] . '&key=' . $plugin['private_key']
                    . '" class="btn btn-block btn-xs btn-primary">'
                    . '<span class="glyphicon glyphicon-upload" aria-hidden="true"></span>&nbsp; Actualizar'
                    . '</a>'
                    . '<a href="#" data-toggle="modal" data-target="#modal_key_' . $plugin['name'] . '">'
                    . '<span class="glyphicon glyphicon-edit" aria-hidden="true"></span> Cambiar la clave'
                    . '</a>'
                    . '</div>'
                    . '</td></tr>';
            }

            if ($this->tr_updates == '') {
                $this->tr_updates = '<tr class="success"><td colspan="5">El sistema está actualizado.'
                    . ' <a href="index.php?page=admin_home&updated=TRUE">Volver</a></td></tr>';
                $this->btn_fin = TRUE;
            }
        }
    }

    private function get_updates()
    {
        /// comprobamos la lista de actualizaciones de cache
        $this->updates = $this->cache->get('updater_lista');
        if ($this->updates) {
            $this->plugin_updates = $this->updates['plugins'];
            return;
        }

        /// si no está en cache, nos toca comprobar todo
        $this->updates = ['version' => '', 'core' => FALSE, 'plugins' => []];

        $version_actual = $this->plugin_manager->version;
        $this->updates['version'] = $version_actual;
        $nueva_version = @fs_file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-framework/refs/heads/master/VERSION');
        if (floatval($version_actual) < floatval($nueva_version)) {
            $this->updates['core'] = $nueva_version;
        } else {
            /// comprobamos los plugins
            foreach ($this->check_for_plugin_updates() as $plugin) {
                $this->updates['plugins'][] = $plugin;
            }
        }

        /// guardamos la lista de actualizaciones en cache
        if (count($this->updates['plugins']) > 0) {
            $this->cache->set('updater_lista', $this->updates);
        }
    }

    private function guardar_key()
    {
        $private_key = filter_input(INPUT_POST, 'key');
        if (file_put_contents('tmp/' . FS_TMP_NAME . 'private_keys/' . filter_input(INPUT_GET, 'idplugin'), $private_key)) {
            $this->core_log->new_message('Clave añadida correctamente.');
            $this->cache->clean();
        } else {
            $this->core_log->new_error('Error al guardar la clave.');
        }
    }

    private function process()
    {
        /// solamente comprobamos si no hay que hacer nada
        if (!filter_input(INPUT_GET, 'update') && !filter_input(INPUT_GET, 'reinstall') && !filter_input(INPUT_GET, 'plugin') && !filter_input(INPUT_GET, 'idplugin')) {
            /// ¿Están todos los permisos correctos?
            foreach (fs_file_manager::not_writable_folders() as $dir) {
                $this->core_log->new_error('No se puede escribir sobre el directorio ' . $dir);
            }

            /// ¿Sigue estando disponible ziparchive?
            if (!extension_loaded('zip')) {
                $this->core_log->new_error('No se encuentra la clase ZipArchive, debes instalar php-zip.');
            }
        }

        if (count($this->core_log->get_errors()) > 0) {
            $this->core_log->new_error('Tienes que corregir estos errores antes de continuar.');
        } else if (filter_input(INPUT_GET, 'update') || filter_input(INPUT_GET, 'reinstall')) {
            $this->actualizar_nucleo();
        } else if (filter_input(INPUT_GET, 'plugin')) {
            $this->actualizar_plugin(filter_input(INPUT_GET, 'plugin'));
        } else if (filter_input(INPUT_GET, 'idplugin') && filter_input(INPUT_GET, 'name') && filter_input(INPUT_GET, 'key')) {
            $this->actualizar_plugin_pago(filter_input(INPUT_GET, 'idplugin'), filter_input(INPUT_GET, 'name'), filter_input(INPUT_GET, 'key'));
        } else if (filter_input(INPUT_GET, 'idplugin') && filter_input(INPUT_GET, 'name') && filter_input(INPUT_POST, 'key')) {
            $this->guardar_key();
        }

        if (count($this->core_log->get_errors()) == 0) {
            $this->comprobar_actualizaciones();
        } else {
            $this->tr_updates = '<tr class="warning"><td colspan="5">Aplazada la comprobación'
                . ' de plugins hasta que resuelvas los problemas.</td></tr>';
        }
    }

    private function xid()
    {
        $this->xid = '';
        $data = $this->cache->get_array('empresa');
        if (!empty($data)) {
            $this->xid = $data[0]['xid'];
            if (!filter_input(INPUT_COOKIE, 'uxid')) {
                setcookie('uxid', $this->xid, time() + FS_COOKIES_EXPIRE);
            }
        } else if (filter_input(INPUT_COOKIE, 'uxid')) {
            $this->xid = filter_input(INPUT_COOKIE, 'uxid');
        }
    }
}
