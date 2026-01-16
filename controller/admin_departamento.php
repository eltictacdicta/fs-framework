<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Controlador para editar un departamento individual.
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class admin_departamento extends fs_controller
{

    public $allow_delete;
    public $departamento;
    
    /** @var bool Indica si el plugin tarifario está activo */
    public $tarifario_activo;
    
    /** @var array Familias asignadas al departamento (si tarifario está activo) */
    public $familias_asignadas;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Editar departamento', 'admin', FALSE, FALSE);
    }

    protected function private_core()
    {
        /// ¿El usuario tiene permiso para eliminar en esta página?
        $this->allow_delete = $this->user->admin;
        
        /// Verificar si el plugin tarifario está activo
        $this->tarifario_activo = $this->is_plugin_active('tarifario');
        $this->familias_asignadas = [];

        if (fs_filter_input_req('coddepartamento')) {
            $fs_departamento = new fs_departamento();
            $this->departamento = $fs_departamento->get(fs_filter_input_req('coddepartamento'));
        }

        if ($this->departamento) {
            // Procesar acciones de familias (si tarifario está activo)
            if ($this->tarifario_activo) {
                $this->process_familias_actions();
                $this->load_familias();
            }
            
            if (filter_input(INPUT_POST, 'nombre')) {
                $this->modify();
            }
        } else {
            $this->new_error_msg("Departamento no encontrado.", 'error', FALSE, FALSE);
        }
    }
    
    /**
     * Verifica si un plugin está activo
     * @param string $plugin_name
     * @return bool
     */
    private function is_plugin_active($plugin_name)
    {
        return in_array($plugin_name, $GLOBALS['plugins']);
    }
    
    /**
     * Procesa las acciones de añadir/quitar familias
     */
    private function process_familias_actions()
    {
        if (isset($_GET['add_familia'])) {
            $rel = new tarif_departamento_familia();
            if ($rel->add($this->departamento->coddepartamento, $_GET['add_familia'])) {
                $this->new_message('Familia añadida correctamente.');
            } else {
                $this->new_error_msg('Error al añadir la familia.');
            }
        } else if (isset($_GET['remove_familia']) && $this->allow_delete) {
            $rel = new tarif_departamento_familia();
            if ($rel->remove($this->departamento->coddepartamento, $_GET['remove_familia'])) {
                $this->new_message('Familia eliminada correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
            }
        }
    }
    
    /**
     * Carga las familias asignadas al departamento
     */
    private function load_familias()
    {
        $rel = new tarif_departamento_familia();
        $this->familias_asignadas = $rel->get_familias_from_departamento($this->departamento->coddepartamento);
    }
    
    /**
     * Devuelve las familias no asignadas a este departamento
     * @return array
     */
    public function get_familias_disponibles()
    {
        if (!$this->tarifario_activo) {
            return [];
        }
        
        $familia = new familia();
        $disponibles = [];
        
        $asignadas = array_map(function($f) { return $f->codfamilia; }, $this->familias_asignadas);
        
        foreach ($familia->all() as $f) {
            if (!in_array($f->codfamilia, $asignadas)) {
                $disponibles[] = $f;
            }
        }
        
        return $disponibles;
    }
    
    /**
     * Cuenta los artículos del tarifario en las familias asignadas
     * @return int
     */
    public function count_articulos_tarifario()
    {
        if (!$this->tarifario_activo || !$this->departamento) {
            return 0;
        }
        
        $rel = new tarif_departamento_familia();
        return $rel->count_articulos_from_departamento($this->departamento->coddepartamento);
    }

    public function all_users()
    {
        $returnlist = [];

        /// Obtenemos la lista de usuarios. Todos
        foreach ($this->user->all() as $u) {
            $u->included = FALSE;
            $u->es_admin_depto = FALSE;
            $returnlist[] = $u;
        }

        /// Completamos con la lista de usuarios del departamento
        $users = $this->departamento->get_users();
        foreach ($returnlist as $i => $value) {
            foreach ($users as $du) {
                if ($value->nick == $du->fs_user) {
                    $returnlist[$i]->included = TRUE;
                    $returnlist[$i]->es_admin_depto = $du->es_admin;
                    break;
                }
            }
        }

        return $returnlist;
    }

    private function modify()
    {
        $this->departamento->nombre = filter_input(INPUT_POST, 'nombre');
        $this->departamento->descripcion = filter_input(INPUT_POST, 'descripcion');
        $this->departamento->activo = (bool) filter_input(INPUT_POST, 'activo');

        if ($this->departamento->save()) {
            /// para cada usuario, comprobamos si hay que incluirlo o no
            $idusers = filter_input(INPUT_POST, 'iuser', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
            $adminusers = filter_input(INPUT_POST, 'iadmin', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

            foreach ($this->all_users() as $u) {
                /**
                 * Creamos un objeto fs_departamento_user con los datos del departamento y el usuario.
                 * Si está incluido guardamos, sino eliminamos.
                 */
                $du = new fs_departamento_user(array(
                    'coddepartamento' => $this->departamento->coddepartamento,
                    'fs_user' => $u->nick,
                    'es_admin' => FALSE
                ));

                if (!$idusers) {
                    /**
                     * No se ha marcado ningún checkbox de usuario, así que eliminamos la relación
                     * con todos los usuarios, uno a uno.
                     */
                    $du->delete();
                } else if (in_array($u->nick, $idusers)) {
                    /// el usuario ha sido marcado como incluido.
                    /// comprobamos si es administrador
                    if ($adminusers && in_array($u->nick, $adminusers)) {
                        $du->es_admin = TRUE;
                    }
                    $du->save();
                } else {
                    /// el usuario no está marcado como incluido.
                    $du->delete();
                }
            }

            $this->new_message('Datos guardados correctamente.');
        } else {
            $this->new_error_msg('Error al guardar los datos.');
        }
    }
}
