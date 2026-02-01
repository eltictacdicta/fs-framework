<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

/**
 * Controlador de admin -> información del sistema.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_info extends fs_list_controller
{

    private $fsvar;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Información del sistema', 'admin', TRUE, TRUE);
    }

    public function cache_version()
    {
        // Usar el nuevo CacheManager si está disponible
        if (class_exists('FSFramework\\Cache\\CacheManager')) {
            return \FSFramework\Cache\CacheManager::getInstance()->version();
        }
        // Fallback al sistema legacy
        return $this->cache->version();
    }

    /**
     * Obtiene información detallada del sistema de caché.
     * 
     * @return array
     */
    public function cache_info()
    {
        if (class_exists('FSFramework\\Cache\\CacheManager')) {
            return \FSFramework\Cache\CacheManager::getInstance()->getInfo();
        }
        return [
            'type' => $this->cache->version(),
            'legacy' => true
        ];
    }

    public function fs_db_name()
    {
        return FS_DB_NAME;
    }

    public function fs_db_version()
    {
        return $this->db->version();
    }

    public function get_locks()
    {
        return $this->db->get_locks();
    }

    public function php_version()
    {
        return phpversion();
    }

    /**
     * Devuelve un string con el número en el formato de número predeterminado.
     * 
     * @param float   $num
     * @param int     $decimales
     * @param boolean $js
     * 
     * @return string
     */
    public function show_numero($num = 0, $decimales = FS_NF0, $js = FALSE)
    {
        if (isset($this->divisa_tools) && $this->divisa_tools !== null) {
            return $this->divisa_tools->show_numero($num, $decimales, $js);
        }
        // Fallback si divisa_tools no está disponible
        return number_format($num, $decimales, FS_NF1, FS_NF2);
    }

    protected function create_tabs()
    {
        /// pestaña historial
        $this->add_tab('logs', 'Historal', 'fs_logs', 'fa-book');
        $this->add_search_columns('logs', ['usuario', 'tipo', 'detalle', 'ip', 'controlador']);
        $this->add_sort_option('logs', ['fecha'], 2);
        $this->add_button('logs', 'Borrar', $this->url() . '&action=remove-all', 'fa-trash', 'btn-danger');

        /// filtros
        $tipos = $this->sql_distinct('fs_logs', 'tipo');
        $this->add_filter_select('logs', 'tipo', 'tipo', $tipos);
        $this->add_filter_date('logs', 'fecha', 'desde', '>=');
        $this->add_filter_date('logs', 'fecha', 'hasta', '<=');
        $this->add_filter_checkbox('logs', 'alerta', 'alerta');

        /// decoración
        $this->decoration->add_column('logs', 'fecha', 'datetime');
        $this->decoration->add_column('logs', 'alerta', 'bool');
        $this->decoration->add_column('logs', 'usuario');
        $this->decoration->add_column('logs', 'tipo');
        $this->decoration->add_column('logs', 'detalle');
        $this->decoration->add_column('logs', 'ip');
        $this->decoration->add_column('logs', 'controlador', 'string', 'página', 'text-right', 'index.php?page=');
        $this->decoration->add_row_option('logs', 'alerta', true, 'danger');
        $this->decoration->add_row_option('logs', 'tipo', 'error', 'danger');
        $this->decoration->add_row_option('logs', 'tipo', 'msg', 'success');

        /// cargamos una plantilla propia para la parte de arriba
        $this->template_top = 'block/admin_info_top';
    }

    protected function exec_previous_action($action)
    {
        switch ($action) {
            case 'remove-all':
                return $this->remove_all_action();

            default:
                return parent::exec_previous_action($action);
        }
    }

    protected function private_core()
    {
        parent::private_core();

        /**
         * Cargamos las variables del cron
         */
        $this->fsvar = new fs_var();
        $cron_vars = $this->fsvar->array_get(
            [
                'cron_exists' => FALSE,
                'cron_lock' => FALSE,
                'cron_error' => FALSE
            ]
        );

        if (isset($_GET['fix'])) {
            $cron_vars['cron_error'] = FALSE;
            $cron_vars['cron_lock'] = FALSE;
            $this->fsvar->array_save($cron_vars);
        } else if (isset($_GET['clean_cache'])) {
            $this->clean_all_cache();
        } else if (!$cron_vars['cron_exists']) {
            $this->new_advice('Nunca se ha ejecutado el'
                . ' <a href="https://github.com/eltictacdicta/fs-framework/doc/2/configuracion/en-cron" target="_blank">cron</a>,'
                . ' te perderás algunas características interesantes de FSFramework.');
        } else if ($cron_vars['cron_error']) {
            $this->new_error_msg('Parece que ha habido un error con el cron. Haz clic <a href="' . $this->url()
                . '&fix=TRUE">aquí</a> para corregirlo.');
        } else if ($cron_vars['cron_lock']) {
            $this->new_advice('Se está ejecutando el cron.');
        }
    }

    protected function remove_all_action()
    {
        $sql = "DELETE FROM fs_logs;";
        if ($this->db->exec($sql)) {
            $this->new_message('Historial borrado correctamente.', true);
        }

        return true;
    }

    /**
     * Limpia todas las cachés del sistema.
     * Usa el nuevo CacheManager con soporte legacy.
     */
    protected function clean_all_cache()
    {
        $messages = [];
        $hasErrors = false;

        // Usar el nuevo CacheManager si está disponible
        if (class_exists('FSFramework\\Cache\\CacheManager')) {
            $cacheManager = \FSFramework\Cache\CacheManager::getInstance();
            $results = $cacheManager->clearAll();
            
            foreach ($results as $type => $success) {
                $typeName = match($type) {
                    'symfony' => 'Caché Symfony',
                    'twig' => 'Caché Twig',
                    'legacy_templates' => 'Plantillas RainTPL',
                    'legacy_file_cache' => 'Caché de archivos legacy',
                    'legacy_memcache' => 'Memcache legacy',
                    default => ucfirst($type)
                };
                
                if ($success) {
                    $messages[] = $typeName . ' limpiada';
                } else {
                    $messages[] = $typeName . ' (error)';
                    $hasErrors = true;
                }
            }
            
            if (!$hasErrors) {
                $this->new_message('Caché limpiada correctamente: ' . implode(', ', $messages));
            } else {
                $this->new_advice('Caché parcialmente limpiada: ' . implode(', ', $messages));
            }
        } else {
            // Fallback al sistema legacy
            fs_file_manager::clear_raintpl_cache();
            fs_file_manager::clear_twig_cache();
            
            if ($this->cache->clean()) {
                $this->new_message('Caché limpiada correctamente (modo legacy).');
            } else {
                $this->new_error_msg('Error al limpiar la caché.');
            }
        }
    }
}
