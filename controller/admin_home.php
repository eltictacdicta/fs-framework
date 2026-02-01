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
require_once 'base/fs_plugin_manager.php';
require_once 'base/fs_settings.php';

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
     * Plugin pendiente de descarga
     * @var array|null
     */
    public $pending_download;

    /**
     * Plugin privado pendiente de descarga
     * @var array|null
     */
    public $pending_private_download;

    /**
     * Resultado del test de conexión privada
     * @var array|null
     */
    public $private_test_result;

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
        $this->pending_plugin = isset($_SESSION['pending_plugin']) ? $_SESSION['pending_plugin'] : null;
        $this->pending_download = isset($_SESSION['pending_download']) ? $_SESSION['pending_download'] : null;
        $this->pending_private_download = isset($_SESSION['pending_private_download']) ? $_SESSION['pending_private_download'] : null;
        $this->private_test_result = null;

        $this->exec_actions();

        $this->paginas = $this->all_pages();
        $this->load_menu(TRUE);
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

        if (filter_input(INPUT_GET, 'download_plugin')) {
            /// descargar plugin como archivo ZIP
            $this->download_plugin(filter_input(INPUT_GET, 'download_plugin'));
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
            /// restaurar plugin desde backup
            $this->restore_plugin_backup(filter_input(INPUT_GET, 'restore_backup'));
            return;
        }

        if (filter_input(INPUT_POST, 'cancel_pending_install')) {
            /// cancelar instalación pendiente
            if (isset($_SESSION['pending_plugin'])) {
                if (file_exists($_SESSION['pending_plugin']['temp_file'])) {
                    unlink($_SESSION['pending_plugin']['temp_file']);
                }
                unset($_SESSION['pending_plugin']);
            }
            return;
        }

        if (filter_input(INPUT_POST, 'cancel_pending_download')) {
            /// cancelar descarga pendiente
            if (isset($_SESSION['pending_download'])) {
                unset($_SESSION['pending_download']);
            }
            return;
        }

        if (filter_input(INPUT_POST, 'cancel_pending_private_download')) {
            /// cancelar descarga privada pendiente
            if (isset($_SESSION['pending_private_download'])) {
                unset($_SESSION['pending_private_download']);
            }
            return;
        }

        if (filter_input(INPUT_POST, 'save_private_config')) {
            /// guardar configuración de plugins privados
            $this->save_private_plugins_config();
            return;
        }

        if (filter_input(INPUT_GET, 'delete_private_config')) {
            /// eliminar configuración de plugins privados
            $this->delete_private_plugins_config();
            return;
        }

        if (filter_input(INPUT_GET, 'test_private_connection')) {
            /// probar conexión con plugins privados
            $this->test_private_plugins_connection();
            return;
        }

        if (filter_input(INPUT_GET, 'refresh_private_plugins')) {
            /// refrescar lista de plugins privados
            $this->refresh_private_plugins();
            return;
        }

        if (filter_input(INPUT_GET, 'download_private')) {
            /// descargar un plugin privado
            $this->download_private(filter_input(INPUT_GET, 'download_private'));
            return;
        }

        if (filter_input(INPUT_GET, 'debug_private_ini')) {
            /// debug de lectura de ini remoto
            $this->template = false;
            header('Content-Type: application/json');
            echo json_encode($this->plugin_manager->debug_remote_ini(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
            $this->install_plugin();
        } else if (filter_input(INPUT_GET, 'download')) {
            /// descargamos un plugin de la lista de la comunidad
            $this->download(filter_input(INPUT_GET, 'download'));
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
        $GLOBALS['config2']['check_db_types'] = mt_rand(0, 1);
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
            $modernPath = FS_FOLDER . '/plugins/' . $plugin . '/Controller';
            if (file_exists($modernPath)) {
                foreach (fs_file_manager::scan_files($modernPath, 'php') as $file_name) {
                    $className = substr($file_name, 0, -4);
                    $fullClass = "FacturaScripts\\Plugins\\$plugin\\Controller\\$className";

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
     * Descarga un plugin de la lista dinámica de la comunidad.
     */
    private function download($plugin_id)
    {
        // Verificar si el plugin ya existe antes de descargar
        $downloads = $this->plugin_manager->downloads();
        $plugin_name = null;

        foreach ($downloads as $item) {
            if ($item['id'] == (int) $plugin_id) {
                $plugin_name = $item['nombre'];
                break;
            }
        }

        if (!$plugin_name) {
            $this->new_error_msg('Plugin no encontrado en la lista de descargas.');
            return;
        }

        $existing_plugin = $this->plugin_manager->check_plugin_exists($plugin_name);

        if ($existing_plugin && !filter_input(INPUT_GET, 'confirm_download')) {
            // Guardar información en sesión para el modal
            $_SESSION['pending_download'] = [
                'plugin_id' => $plugin_id,
                'name' => $plugin_name,
                'current_version' => $existing_plugin['version']
            ];

            $this->new_advice('El plugin <b>' . $plugin_name . '</b> ya existe. Se requiere confirmación para sobrescribir.');
            return;
        }

        // Si hay confirmación, descargar con backup
        if (filter_input(INPUT_GET, 'confirm_download') && isset($_SESSION['pending_download'])) {
            $pending = $_SESSION['pending_download'];
            $this->plugin_manager->download($pending['plugin_id'], true);
            unset($_SESSION['pending_download']);
            return;
        }

        // Plugin nuevo, descargar directamente
        $this->plugin_manager->download($plugin_id, false);
    }

    /**
     * Restaura un plugin desde su backup
     *
     * @param string $plugin_name
     */
    private function restore_plugin_backup($plugin_name)
    {
        $plugin_name = basename($plugin_name);
        // Desactivar el plugin si está activo
        if (in_array($plugin_name, $this->plugin_manager->enabled())) {
            $this->plugin_manager->disable($plugin_name);
        }

        // Restaurar el backup
        if ($this->plugin_manager->restore_backup($plugin_name)) {
            $this->new_message('Plugin <b>' . $plugin_name . '</b> restaurado correctamente desde el backup.');
        }
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

        require_once $class_name;
        $new_fsc = new $page->name();
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

    private function install_plugin()
    {
        if (!is_uploaded_file($_FILES['fplugin']['tmp_name'])) {
            $this->new_error_msg('Archivo no encontrado. ¿Pesa más de '
                . $this->get_max_file_upload() . ' MB? Ese es el límite que tienes'
                . ' configurado en tu servidor.');
            return;
        }

        // Detectar el plugin desde el ZIP
        $plugin_info = $this->plugin_manager->detect_plugin_from_zip($_FILES['fplugin']['tmp_name']);

        if (!$plugin_info) {
            $this->new_error_msg('Error al leer el archivo ZIP del plugin.');
            return;
        }

        $plugin_name = $plugin_info['name'];
        $new_version = $plugin_info['version'];

        // Verificar si el plugin ya existe
        $existing_plugin = $this->plugin_manager->check_plugin_exists($plugin_name);

        if ($existing_plugin && !filter_input(INPUT_POST, 'confirm_overwrite')) {
            // El plugin existe y no hay confirmación, guardar el archivo temporalmente
            $temp_file = FS_FOLDER . '/tmp/plugin_pending_install.zip';
            move_uploaded_file($_FILES['fplugin']['tmp_name'], $temp_file);

            // Guardar información en sesión para el modal
            $_SESSION['pending_plugin'] = [
                'name' => $plugin_name,
                'new_version' => $new_version,
                'current_version' => $existing_plugin['version'],
                'temp_file' => $temp_file
            ];

            $this->new_advice('El plugin <b>' . $plugin_name . '</b> ya existe. Se requiere confirmación para sobrescribir.');
            return;
        }

        // Si hay confirmación pendiente, procesarla
        if (filter_input(INPUT_POST, 'confirm_overwrite') && isset($_SESSION['pending_plugin'])) {
            $pending = $_SESSION['pending_plugin'];

            if (!file_exists($pending['temp_file'])) {
                $this->new_error_msg('El archivo temporal del plugin no se encuentra.');
                unset($_SESSION['pending_plugin']);
                return;
            }

            // Instalar con backup
            $result = $this->plugin_manager->install($pending['temp_file'], $pending['name'] . '.zip', true);

            // Limpiar archivo temporal
            if (file_exists($pending['temp_file'])) {
                unlink($pending['temp_file']);
            }
            unset($_SESSION['pending_plugin']);

            if ($result) {
                $this->new_message('Plugin <b>' . $result . '</b> instalado correctamente. El plugin anterior se guardó como backup.');
            }
            return;
        }

        // Plugin nuevo, instalar directamente
        $this->plugin_manager->install($_FILES['fplugin']['tmp_name'], $_FILES['fplugin']['name'], false);
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
    private function download_plugin($plugin_name)
    {
        $plugin_name = basename($plugin_name);
        $plugin_path = FS_FOLDER . '/plugins/' . $plugin_name;

        // Verificar que el plugin existe
        if (!file_exists($plugin_path) || !is_dir($plugin_path)) {
            $this->new_error_msg('El plugin <b>' . $plugin_name . '</b> no existe.');
            return;
        }

        // Crear el archivo ZIP temporal
        $zip_filename = $plugin_name . '.zip';
        $zip_path = FS_FOLDER . '/tmp/' . $zip_filename;

        // Asegurarse de que la carpeta tmp existe
        if (!file_exists(FS_FOLDER . '/tmp')) {
            mkdir(FS_FOLDER . '/tmp', 0777, true);
        }

        // Crear el archivo ZIP
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            $this->new_error_msg('Error al crear el archivo ZIP para el plugin <b>' . $plugin_name . '</b>.');
            return;
        }

        // Agregar archivos al ZIP recursivamente
        $this->add_files_to_zip($zip, $plugin_path, $plugin_name);
        $zip->close();

        // Verificar que el archivo se creó correctamente
        if (!file_exists($zip_path)) {
            $this->new_error_msg('Error al crear el archivo ZIP.');
            return;
        }

        // Desactivar el template para enviar el archivo
        $this->template = FALSE;

        // Enviar headers para la descarga
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Enviar el archivo
        readfile($zip_path);

        // Eliminar el archivo temporal
        unlink($zip_path);
    }

    /**
     * Agrega archivos a un ZIP de forma recursiva.
     * 
     * @param ZipArchive $zip
     * @param string $source_path
     * @param string $base_path
     */
    private function add_files_to_zip($zip, $source_path, $base_path)
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $file_path = $file->getRealPath();
            $relative_path = $base_path . '/' . substr($file_path, strlen($source_path) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($file_path, $relative_path);
            }
        }
    }

    /**
     * Devuelve el símbolo de divisa predeterminado
     * o bien el símbolo de la divisa seleccionada.
     * 
     * @param string $coddivisa
     * @return string
     */
    public function simbolo_divisa($coddivisa = FALSE)
    {
        if (!isset($this->divisa_tools)) {
            return '€'; // Valor por defecto si no está inicializado
        }
        return $this->divisa_tools->simbolo_divisa($coddivisa);
    }

    /**
     * Guarda la configuración de plugins privados.
     */
    private function save_private_plugins_config()
    {
        $github_token = filter_input(INPUT_POST, 'github_token');
        $private_plugins_url = filter_input(INPUT_POST, 'private_plugins_url');

        if (empty($github_token) || empty($private_plugins_url)) {
            $this->new_error_msg('Debes proporcionar tanto el token de GitHub como la URL del JSON de plugins.');
            return;
        }

        // Validar que la URL tenga un formato válido
        if (!filter_var($private_plugins_url, FILTER_VALIDATE_URL)) {
            $this->new_error_msg('La URL proporcionada no es válida.');
            return;
        }

        if ($this->plugin_manager->save_private_config($github_token, $private_plugins_url)) {
            $this->new_message('Configuración de plugins privados guardada correctamente.');

            // Probar la conexión automáticamente
            $test_result = $this->plugin_manager->test_private_connection();
            if ($test_result['success']) {
                $this->new_message($test_result['message']);
            } else {
                $this->new_advice($test_result['message']);
            }
        } else {
            $this->new_error_msg('Error al guardar la configuración.');
        }
    }

    /**
     * Elimina la configuración de plugins privados.
     */
    private function delete_private_plugins_config()
    {
        if ($this->plugin_manager->delete_private_config()) {
            $this->new_message('Configuración de plugins privados eliminada correctamente.');
        } else {
            $this->new_error_msg('Error al eliminar la configuración.');
        }
    }

    /**
     * Prueba la conexión con plugins privados.
     */
    private function test_private_plugins_connection()
    {
        $this->private_test_result = $this->plugin_manager->test_private_connection();

        if ($this->private_test_result['success']) {
            $this->new_message($this->private_test_result['message']);
        } else {
            $this->new_error_msg($this->private_test_result['message']);
        }
    }

    /**
     * Refresca la lista de plugins privados.
     */
    private function refresh_private_plugins()
    {
        // Limpiar TODA la caché para forzar recarga completa
        $this->cache->clean();
        $this->plugin_manager->refresh_private_downloads();
        $this->new_message('Lista de plugins privados actualizada.');
    }

    /**
     * Descarga un plugin privado.
     * Siempre crea backup automático si el plugin ya existe.
     * 
     * @param string $plugin_id
     */
    private function download_private($plugin_id)
    {
        // Limpiar cualquier sesión pendiente anterior
        if (isset($_SESSION['pending_private_download'])) {
            unset($_SESSION['pending_private_download']);
        }
        $this->pending_private_download = null;

        // Descargar el plugin (siempre crea backup si ya existe)
        $this->plugin_manager->download_private($plugin_id);
    }
}
