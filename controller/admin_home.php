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
require_once dirname(__DIR__) . '/base/fs_plugin_manager.php';
require_once dirname(__DIR__) . '/base/fs_settings.php';

/**
 * Panel de control de FSFramework.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_home extends fs_controller
{

    /**
     *
     * @var \fs_var
     */
    private $fs_var;

    /**
     *
     * @var \fs_page[]
     */
    public $paginas;

    /**
     *
     * @var \fs_plugin_manager
     */
    public $plugin_manager;

    /**
     *
     * @var \fs_settings
     */
    public $settings;

    /**
     *
     * @var string
     */
    public $step;

    /**
     * Herramientas para trabajar con divisas
     * @var \fs_divisa_tools
     */
    protected $divisa_tools;

    /**
     * Plugin pendiente de instalación
     * @var array|null
     */
    public $pending_plugin;

    /**
     * Información del actualizador
     * @var array|null
     */
    public $updater_info;

    /**
     * Información de actualización disponible para el actualizador
     * @var array|false
     */
    public $updater_update;

    /**
     * Copias de seguridad recientes
     * @var array
     */
    public $recent_backups;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Panel de control', 'admin');
    }

    /**
     * Comprueba actualizaciones de los plugins y del núcleo.
     *
     * @return boolean
     */
    public function check_for_updates2()
    {
        if (!$this->user->admin) {
            return FALSE;
        }

        /// comprobamos actualizaciones en los plugins
        $updates = FALSE;
        foreach ($this->plugin_manager->installed() as $plugin) {
            if ($plugin['version_url'] != '' && $plugin['update_url'] != '') {
                /// plugin con descarga gratuita
                $internet_ini = @parse_ini_string(@fs_file_get_contents($plugin['version_url']));
                if ($internet_ini && $plugin['version'] < intval($internet_ini['version'])) {
                    $updates = TRUE;
                    break;
                }
            } else if ($plugin['idplugin'] && $plugin['download2_url'] != '') {
                /// plugin de pago/oculto
                /// download2_url implica que hay actualización
                $updates = TRUE;
                break;
            }
        }

        if (!$updates) {
            /// comprobamos actualizaciones del núcleo
            $version = file_get_contents('VERSION');
            $internet_version = @fs_file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-framework/refs/heads/master/VERSION');
            if (floatval($version) < floatval($internet_version)) {
                $updates = TRUE;
            }
        }

        if ($updates) {
            $this->fs_var->simple_save('updates', 'true');
            return TRUE;
        }

        $this->fs_var->name = 'updates';
        $this->fs_var->delete();
        return FALSE;
    }

    public function plugin_advanced_list()
    {
        /**
         * Si se produce alguna llamada a esta función, desactivamos todos los plugins,
         * porque debe haber alguno que está desactualizado, y un problema al cargar
         * está página será muy difícil de resolver para un novato.
         */
        foreach ($this->plugin_manager->enabled() as $plug) {
            $this->plugin_manager->disable($plug);
        }

        return [];
    }

    protected function private_core()
    {
        $this->fs_var = new fs_var();
        $this->plugin_manager = new fs_plugin_manager();
        $this->settings = new fs_settings();
        $this->step = (string) $this->fs_var->simple_get('install_step');

        // Inicializar herramientas de divisas (solo si el plugin business_data está disponible)
        if (class_exists('fs_divisa_tools')) {
            $coddivisa = $this->default_items->coddivisa();
            $this->divisa_tools = new fs_divisa_tools($coddivisa);
        }

        // Inicializar variables para plugins pendientes
        $this->sync_pending_actions();

        // Inicializar información del actualizador
        $this->init_updater_info();

        // Auto-actualizar cacert.pem si tiene más de 90 días
        $this->check_ca_bundle_update();

        $this->exec_actions();

        $this->paginas = $this->all_pages();
        $this->load_menu(TRUE);
    }

    private function sync_pending_actions()
    {
        $this->pending_plugin = isset($_SESSION['pending_plugin']) ? $_SESSION['pending_plugin'] : null;
    }

    /**
     * Inicializa la información del módulo actualizador
     */
    private function init_updater_info()
    {
        $this->updater_info = null;
        $this->updater_update = false;
        $this->recent_backups = [];

        $updaterManagerPath = FS_FOLDER . '/update-and-backup/updater_manager.php';
        $backupManagerPath = FS_FOLDER . '/update-and-backup/fs_backup_manager.php';

        if (file_exists($updaterManagerPath)) {
            require_once $updaterManagerPath;
            $updaterManager = new \updater_manager();
            $this->updater_info = $updaterManager->get_info();
            $this->updater_update = $updaterManager->check_for_updates();
        }

        if (file_exists($backupManagerPath)) {
            require_once $backupManagerPath;
            $backupManager = new \fs_backup_manager();
            $this->recent_backups = $backupManager->list_backups();
        }
    }

    /**
     * Descarga e instala automáticamente el plugin system_updater desde GitHub.
     * Se invoca cuando el usuario pulsa "Actualizador" y el plugin no está instalado.
     */
    private function install_system_updater()
    {
        $result = (new \FSFramework\Core\PluginInstaller($this->plugin_manager))->installSystemUpdater();
        $this->applyHandlerResult($result);

        if (!empty($result['redirect'])) {
            header('Location: ' . $result['redirect']);
            exit;
        }
    }

    private function exec_actions()
    {
        if (filter_input(INPUT_GET, 'check4updates')) {
            $this->template = FALSE;
            if ($this->check_for_updates2()) {
                echo 'Hay actualizaciones disponibles.';
            } else {
                echo 'No hay actualizaciones.';
            }
            return;
        }

        if (filter_input(INPUT_GET, 'updated')) {
            /// el sistema ya se ha actualizado
            $this->fs_var->simple_delete('updates');
            $this->activar_comprobacion_columnas();
            $this->clean_cache();
            return;
        }



        if (FS_DEMO) {
            $this->new_advice('En el modo demo no se pueden hacer cambios en esta página.');
            $this->new_advice('Si te gusta FSFramework y quieres saber más, consulta la '
                . '<a href="https://github.com/eltictacdicta/fs-framework">documentación</a>.');
            return;
        }

        if (!$this->user->admin) {
            $this->new_error_msg('Sólo un administrador puede hacer cambios en esta página.');
            return;
        }

        if (filter_input(INPUT_GET, 'install_system_updater')) {
            /// instalar automáticamente el plugin system_updater desde GitHub
            $this->install_system_updater();
            return;
        }

        if (filter_input(INPUT_GET, 'download_plugin')) {
            /// descargar plugin como archivo ZIP
            $handlerResult = (new \FSFramework\Core\PluginActionHandler($this->plugin_manager))->handle();
            if (!empty($handlerResult['errors'])) {
                $this->applyHandlerResult($handlerResult);
                return;
            }

            if (!empty($handlerResult['download_zip'])) {
                $this->template = FALSE;
                $zip = $handlerResult['download_zip'];
                $filename = $this->sanitizeDownloadFilename((string) ($zip['filename'] ?? 'plugin.zip'));
                $zipPath = (string) ($zip['path'] ?? '');
                $zipSize = is_file($zipPath) ? filesize($zipPath) : false;

                if ($zipPath === '' || !is_readable($zipPath) || $zipSize === false) {
                    $this->new_error_msg('No se pudo preparar la descarga del plugin.');
                    $this->applyHandlerResult($handlerResult);
                    return;
                }

                register_shutdown_function(static function () use ($zipPath): void {
                    if (is_file($zipPath) && !@unlink($zipPath)) {
                        error_log('FSFramework admin_home: no se pudo eliminar el ZIP temporal ' . $zipPath);
                    }
                });

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
                header('Content-Length: ' . $zipSize);
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');

                if (readfile($zipPath) === false) {
                    error_log('FSFramework admin_home: fallo al enviar el ZIP temporal ' . $zipPath);
                }

                exit;
            }

            $this->applyHandlerResult($handlerResult);
            return;
        }

        if (filter_input(INPUT_GET, 'activate_theme')) {
            /// activar tema
            $themeName = filter_input(INPUT_GET, 'activate_theme');
            $themeManager = \FSFramework\Core\ThemeManager::getInstance();
            if ($themeManager->activateTheme($themeName)) {
                $this->new_message('Tema <b>' . htmlspecialchars($themeName) . '</b> activado correctamente.');
            } else {
                $this->new_error_msg('Error al activar el tema <b>' . htmlspecialchars($themeName) . '</b>.');
            }
            return;
        }

        if (filter_input(INPUT_GET, 'skip')) {
            if ($this->step == '1') {
                $this->step = '2';
                $this->fs_var->simple_save('install_step', $this->step);
            }
            return;
        }

        if (filter_input(INPUT_GET, 'restore_backup')) {
            $handlerResult = (new \FSFramework\Core\PluginActionHandler($this->plugin_manager))->handle();
            $this->applyHandlerResult($handlerResult);
            return;
        }

        if ($this->request->isMethod('POST') && !$this->requireCsrf()) {
            return;
        }

        if (filter_input(INPUT_POST, 'cancel_pending_install')) {
            $handlerResult = (new \FSFramework\Core\PluginActionHandler($this->plugin_manager))->handle();
            $this->applyHandlerResult($handlerResult);
            $this->sync_pending_actions();
            return;
        }

        if (filter_input(INPUT_POST, 'modpages')) {
            /// activar/desactivas páginas del menú
            $this->enable_pages();
        } else if (filter_input(INPUT_GET, 'enable')) {
            /// activar plugin
            $this->enable_plugin(filter_input(INPUT_GET, 'enable'));
        } else if (filter_input(INPUT_GET, 'disable')) {
            /// desactivar plugin
            $this->disable_plugin(filter_input(INPUT_GET, 'disable'));
        } else if (filter_input(INPUT_GET, 'delete_plugin')) {
            /// eliminar plugin
            $this->delete_plugin(filter_input(INPUT_GET, 'delete_plugin'));
        } else if (filter_input(INPUT_POST, 'install')) {
            /// instalar plugin (copiarlo y descomprimirlo)
            $handlerResult = (new \FSFramework\Core\PluginActionHandler($this->plugin_manager))->handle();
            $this->pending_plugin = $_SESSION['pending_plugin'] ?? null;
            $this->applyHandlerResult($handlerResult);
        } else if (filter_input(INPUT_GET, 'reset')) {
            /// reseteamos la configuración avanzada
            $this->settings->reset();
            $this->new_message('Configuración reiniciada correctamente, pulsa <a href="' . $this->url() . '#avanzado">aquí</a> para continuar.', TRUE);
            return;
        }

        fs_file_manager::check_htaccess();

        /// ¿Guardamos las opciones de la pestaña avanzado?
        $this->save_avanzado();
    }

    /**
     * Activamos/desactivamos aleatoriamente la comprobación de tipos de las columnas
     * de las tablas. ¿Por qué? Porque la comprobación es lenta y no merece la pena hacerla
     * siempre, pero tras las actualizaciones puede haber cambios en las columnas de las tablas.
     */
    private function activar_comprobacion_columnas()
    {
        $GLOBALS['config2']['check_db_types'] = random_int(0, 1);
        $this->settings->save();
    }

    /**
     * Devuelve las páginas/controladore de los plugins activos.
     * Soporta tanto plugins legacy (controller/) como FS2025 (Controller/).
     *
     * @return \fs_page[]
     */
    private function all_pages()
    {
        $pages = [];
        $page_names = [];

        /// añadimos las páginas de los plugins
        foreach ($this->plugin_manager->enabled() as $plugin) {
            // Legacy plugins: controller/ folder (lowercase)
            $legacyPath = FS_FOLDER . '/plugins/' . $plugin . '/controller';
            if (file_exists($legacyPath)) {
                foreach (fs_file_manager::scan_files($legacyPath, 'php') as $file_name) {
                    $p = new fs_page();
                    $p->name = substr($file_name, 0, -4);
                    $p->exists = TRUE;
                    $p->show_on_menu = FALSE;
                    if (!in_array($p->name, $page_names)) {
                        $pages[] = $p;
                        $page_names[] = $p->name;
                    }
                }
            }

            // FS2025 plugins: Controller/ folder (PascalCase)
            // Only include page controllers, NOT route controllers (FSRoute)
            $modernPath = FS_FOLDER . '/plugins/' . $plugin . '/Controller';
            if (file_exists($modernPath)) {
                foreach (fs_file_manager::scan_files($modernPath, 'php') as $file_name) {
                    $className = substr($file_name, 0, -4);
                    if (!fs_is_modern_controller_basename($className)) {
                        continue;
                    }

                    $fullClass = "FSFramework\\Plugins\\$plugin\\Controller\\$className";

                    // Skip route controllers (they use #[FSRoute] and are not CMS pages)
                    if (fs_is_route_controller($fullClass)) {
                        continue;
                    }
                    
                    // Only include if it's a proper page controller
                    if (!fs_is_page_controller($fullClass)) {
                        continue;
                    }

                    // Get page name from getPageData() if available
                    $pageName = $className;
                    if (class_exists($fullClass)) {
                        try {
                            $reflection = new \ReflectionClass($fullClass);
                            $tempInstance = $reflection->newInstanceWithoutConstructor();
                            if (method_exists($tempInstance, 'getPageData')) {
                                $pd = $tempInstance->getPageData();
                                if (isset($pd['name']) && !empty($pd['name'])) {
                                    $pageName = $pd['name'];
                                }
                            }
                        } catch (\Throwable $e) {
                            // Use class name as fallback
                        }
                    }

                    if (!in_array($pageName, $page_names)) {
                        $p = new fs_page();
                        $p->name = $pageName;
                        $p->exists = TRUE;
                        $p->show_on_menu = FALSE;
                        $pages[] = $p;
                        $page_names[] = $pageName;
                    }
                }
            }
        }

        /// añadimos las páginas que están en el directorio controller
        foreach (fs_file_manager::scan_files(FS_FOLDER . '/controller', 'php') as $file_name) {
            $p = new fs_page();
            $p->name = substr($file_name, 0, -4);
            $p->exists = TRUE;
            $p->show_on_menu = FALSE;
            if (!in_array($p->name, $page_names)) {
                $pages[] = $p;
                $page_names[] = $p->name;
            }
        }

        /// completamos los datos de las páginas con los datos de la base de datos
        foreach ($this->page->all() as $p) {
            $encontrada = FALSE;
            foreach ($pages as $i => $value) {
                if ($p->name == $value->name) {
                    $pages[$i] = $p;
                    $pages[$i]->enabled = TRUE;
                    $pages[$i]->exists = TRUE;
                    $encontrada = TRUE;
                    break;
                }
            }
            if (!$encontrada) {
                $p->enabled = TRUE;
                $pages[] = $p;
            }
        }

        /// ordenamos
        usort($pages, function ($a, $b) {
            if ($a->name == $b->name) {
                return 0;
            } else if ($a->name > $b->name) {
                return 1;
            }

            return -1;
        });

        return $pages;
    }

    /**
     * Comprueba si el CA bundle (cacert.pem) necesita actualizarse.
     * Se ejecuta silenciosamente una vez por sesión. Si se actualiza
     * correctamente, muestra un mensaje informativo al admin.
     */
    private function check_ca_bundle_update()
    {
        if (function_exists('fs_curl_update_ca_bundle') && fs_curl_update_ca_bundle()) {
            $this->new_message('El archivo de certificados SSL (cacert.pem) se actualizó automáticamente.');
        }
    }

    private function clean_cache()
    {
        $this->cache->clean();
        fs_file_manager::clear_raintpl_cache();
    }

    /**
     * Elimina el plugin del directorio.
     * 
     * @param string $name
     */
    private function delete_plugin($name)
    {
        $name = basename($name);
        $this->plugin_manager->remove($name);
    }

    /**
     * Desactiva una página/controlador.
     * 
     * @param fs_page $page
     */
    private function disable_page($page)
    {
        if ($page->name == $this->page->name) {
            $this->new_error_msg("No puedes desactivar esta página (" . $page->name . ").");
            return false;
        } else if (!$page->delete()) {
            $this->new_error_msg('Imposible eliminar la página ' . $page->name . '.');
            return false;
        }

        return true;
    }

    /**
     * Desactiva un plugin.
     *
     * @param string $name
     */
    private function disable_plugin($name)
    {
        $name = basename($name);
        $this->plugin_manager->disable($name);
    }

    /**
     * Restaura un plugin desde su backup
     *
     * @param string $plugin_name
     */
    private function applyHandlerResult(array $result): void
    {
        foreach ($result['messages'] ?? [] as $msg) {
            $this->new_message($msg);
        }
        foreach ($result['errors'] ?? [] as $err) {
            $this->new_error_msg($err);
        }
        foreach ($result['advices'] ?? [] as $adv) {
            $this->new_advice($adv);
        }
    }

    private function sanitizeDownloadFilename(string $filename): string
    {
        $filename = str_replace(["\r", "\n", '"', "'"], '', $filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        if ($filename === '') {
            return 'plugin.zip';
        }

        if (substr(strtolower($filename), -4) !== '.zip') {
            return $filename . '.zip';
        }

        return $filename;
    }

    /**
     * Activa una página/controlador.
     * Soporta tanto controladores legacy como FS2025.
     * 
     * @param \fs_page $page
     */
    private function enable_page($page)
    {
        // First, check for FS2025 modern controller
        $modernController = find_modern_controller($page->name);
        if ($modernController) {
            // FS2025 controller found - create/update page entry
            try {
                $fullClass = $modernController['class'];
                $controller = new $fullClass();

                // The constructor of FS2025 controllers already saves the page
                // Just verify it was saved correctly
                if (isset($controller->page) && $controller->page->exists()) {
                    return true;
                }

                // Fallback: manually get page data and save
                if (method_exists($controller, 'getPageData')) {
                    $pageData = $controller->getPageData();
                    $page->title = $pageData['title'] ?? $page->name;
                    $page->folder = $pageData['menu'] ?? 'admin';
                    $page->show_on_menu = $pageData['showonmenu'] ?? true;
                    $page->orden = $pageData['ordernum'] ?? 100;
                    return $page->save();
                }
            } catch (\Throwable $e) {
                $this->new_error_msg("Error al cargar controlador FS2025 <b>" . $page->name . "</b>: " . $e->getMessage());
                return false;
            }
        }

        // Legacy controller handling
        $class_name = find_controller($page->name);
        /// ¿No se ha encontrado el controlador?
        if ('base/fs_controller.php' === $class_name) {
            $this->new_error_msg('Controlador <b>' . $page->name . '</b> no encontrado.');
            return false;
        }

        $controllerPath = preg_match('#^/#', $class_name) === 1 ? $class_name : FS_FOLDER . '/' . $class_name;
        require_once $controllerPath;
        $new_fsc = new $page->name();
        if ($new_fsc instanceof login) {
            $new_fsc->skipLoginLogic();
        }
        if (!isset($new_fsc->page)) {
            $this->new_error_msg("Error al leer la página " . $page->name);
            return false;
        } elseif (!$new_fsc->page->save()) {
            $this->new_error_msg("Imposible guardar la página " . $page->name);
            return false;
        }

        unset($new_fsc);
        return true;
    }

    private function enable_pages()
    {
        if (!$this->step) {
            $this->step = '1';
            $this->fs_var->simple_save('install_step', $this->step);
        }

        $enabled = filter_input(INPUT_POST, 'enabled', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
        foreach ($this->all_pages() as $p) {
            if (!$p->exists) { /// la página está en la base de datos pero ya no existe el controlador
                if ($p->delete()) {
                    $this->new_message('Se ha eliminado automáticamente la página ' . $p->name .
                        ' ya que no tiene un controlador asociado en la carpeta controller.');
                }
            } else if (!$enabled) { /// ninguna página marcada
                $this->disable_page($p);
            } else if (!$p->enabled && in_array($p->name, $enabled)) { /// página no activa marcada para activar
                $this->enable_page($p);
            } else if ($p->enabled && !in_array($p->name, $enabled)) { /// págine activa no marcada (desactivar)
                $this->disable_page($p);
            }
        }

        $this->new_message('Datos guardados correctamente.');
    }

    /**
     * Activa un plugin.
     *
     * @param string $name
     */
    private function enable_plugin($name)
    {
        $name = basename($name);
        if (!$this->plugin_manager->enable($name)) {
            return;
        }

        $this->load_menu(TRUE);

        if ($this->step == '1') {
            $this->step = '2';
            $this->fs_var->simple_save('install_step', $this->step);
        }
    }

    private function save_avanzado()
    {
        $guardar = FALSE;
        foreach ($GLOBALS['config2'] as $i => $value) {
            if (filter_input(INPUT_POST, $i) !== NULL) {
                $GLOBALS['config2'][$i] = filter_input(INPUT_POST, $i);
                $guardar = TRUE;
            }
        }

        if (!$guardar) {
            return;
        }

        if ($this->settings->save()) {
            $this->new_message('Datos guardados correctamente.');
        } else {
            $this->new_message('Error al guardar los datos.');
        }
    }

    /**
     * Descarga un plugin como archivo ZIP.
     * 
     * @param string $plugin_name
     */
    public function simbolo_divisa($coddivisa = FALSE)
    {
        if (!isset($this->divisa_tools)) {
            return '€'; // Valor por defecto si no está inicializado
        }
        return $this->divisa_tools->simbolo_divisa($coddivisa);
    }

}
