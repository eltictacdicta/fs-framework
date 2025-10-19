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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Controlador de admin -> agentes.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_agentes extends fs_controller
{

    public $agente;
    public $modificar;
    public $nuevo_agente;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Agentes', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        $this->agente = new agente();
        $this->modificar = FALSE;
        $this->nuevo_agente = FALSE;

        if (filter_input(INPUT_POST, 'codagente') !== NULL) {
            $this->save_agente();
        } else if (filter_input(INPUT_GET, 'delete')) {
            $this->delete_agente();
        } else if (filter_input(INPUT_GET, 'cod')) {
            $this->modificar_agente();
        } else if (filter_input(INPUT_GET, 'nuevo')) {
            $this->nuevo_agente = TRUE;
        }
    }

    private function save_agente()
    {
        $agente_data = array(
            'codagente' => filter_input(INPUT_POST, 'codagente'),
            'nombre' => filter_input(INPUT_POST, 'nombre'),
            'apellidos' => filter_input(INPUT_POST, 'apellidos'),
            'dnicif' => filter_input(INPUT_POST, 'dnicif'),
            'email' => filter_input(INPUT_POST, 'email'),
            'telefono' => filter_input(INPUT_POST, 'telefono'),
            'codpostal' => filter_input(INPUT_POST, 'codpostal'),
            'provincia' => filter_input(INPUT_POST, 'provincia'),
            'ciudad' => filter_input(INPUT_POST, 'ciudad'),
            'direccion' => filter_input(INPUT_POST, 'direccion'),
            'seg_social' => filter_input(INPUT_POST, 'seg_social'),
            'cargo' => filter_input(INPUT_POST, 'cargo'),
            'banco' => filter_input(INPUT_POST, 'banco'),
            'f_nacimiento' => filter_input(INPUT_POST, 'f_nacimiento'),
            'f_alta' => filter_input(INPUT_POST, 'f_alta'),
            'f_baja' => filter_input(INPUT_POST, 'f_baja'),
            'porcomision' => floatval(filter_input(INPUT_POST, 'porcomision'))
        );

        if (filter_input(INPUT_POST, 'codagente') == '') {
            /// Nuevo agente
            $agente_obj = new agente();
            $agente_obj->nombre = $agente_data['nombre'];
            $agente_obj->apellidos = $agente_data['apellidos'];
            $agente_obj->dnicif = $agente_data['dnicif'];
            $agente_obj->email = $agente_data['email'];
            $agente_obj->telefono = $agente_data['telefono'];
            $agente_obj->codpostal = $agente_data['codpostal'];
            $agente_obj->provincia = $agente_data['provincia'];
            $agente_obj->ciudad = $agente_data['ciudad'];
            $agente_obj->direccion = $agente_data['direccion'];
            $agente_obj->seg_social = $agente_data['seg_social'];
            $agente_obj->cargo = $agente_data['cargo'];
            $agente_obj->banco = $agente_data['banco'];
            $agente_obj->f_nacimiento = ($agente_data['f_nacimiento'] != '') ? $agente_data['f_nacimiento'] : NULL;
            $agente_obj->f_alta = ($agente_data['f_alta'] != '') ? $agente_data['f_alta'] : NULL;
            $agente_obj->f_baja = ($agente_data['f_baja'] != '') ? $agente_data['f_baja'] : NULL;
            $agente_obj->porcomision = $agente_data['porcomision'];

            if ($agente_obj->save()) {
                $this->new_message('Agente ' . $agente_obj->codagente . ' creado correctamente.');
                header('Location: ' . $agente_obj->url());
            } else {
                $this->new_error_msg('Error al crear el agente.');
            }
        } else {
            /// Modificar agente
            $agente_obj = $this->agente->get($agente_data['codagente']);
            if ($agente_obj) {
                $agente_obj->nombre = $agente_data['nombre'];
                $agente_obj->apellidos = $agente_data['apellidos'];
                $agente_obj->dnicif = $agente_data['dnicif'];
                $agente_obj->email = $agente_data['email'];
                $agente_obj->telefono = $agente_data['telefono'];
                $agente_obj->codpostal = $agente_data['codpostal'];
                $agente_obj->provincia = $agente_data['provincia'];
                $agente_obj->ciudad = $agente_data['ciudad'];
                $agente_obj->direccion = $agente_data['direccion'];
                $agente_obj->seg_social = $agente_data['seg_social'];
                $agente_obj->cargo = $agente_data['cargo'];
                $agente_obj->banco = $agente_data['banco'];
                $agente_obj->f_nacimiento = ($agente_data['f_nacimiento'] != '') ? $agente_data['f_nacimiento'] : NULL;
                $agente_obj->f_alta = ($agente_data['f_alta'] != '') ? $agente_data['f_alta'] : NULL;
                $agente_obj->f_baja = ($agente_data['f_baja'] != '') ? $agente_data['f_baja'] : NULL;
                $agente_obj->porcomision = $agente_data['porcomision'];

                if ($agente_obj->save()) {
                    $this->new_message('Agente ' . $agente_obj->codagente . ' modificado correctamente.');
                    header('Location: ' . $agente_obj->url());
                } else {
                    $this->new_error_msg('Error al modificar el agente.');
                }
            } else {
                $this->new_error_msg('Agente no encontrado.');
            }
        }
    }

    private function delete_agente()
    {
        $codagente = filter_input(INPUT_GET, 'delete');
        $agente_obj = $this->agente->get($codagente);

        if ($agente_obj) {
            if (FS_DEMO) {
                $this->new_error_msg('En el modo <b>demo</b> no se pueden eliminar agentes.
                Esto es así para evitar malas prácticas entre usuarios que prueban la demo.');
            } else if ($agente_obj->delete()) {
                $this->new_message("Agente " . $agente_obj->codagente . " eliminado correctamente.");
            } else {
                $this->new_error_msg("¡Imposible eliminar al agente!");
            }
        } else {
            $this->new_error_msg("¡Agente no encontrado!");
        }
    }

    private function modificar_agente()
    {
        $codagente = filter_input(INPUT_GET, 'cod');
        $agente_obj = $this->agente->get($codagente);

        if ($agente_obj) {
            $this->agente = $agente_obj;
            $this->modificar = TRUE;
        } else {
            $this->new_error_msg('Agente no encontrado.');
        }
    }

    public function all_pages()
    {
        $returnlist = [];

        /// Obtenemos la lista de páginas. Todas
        foreach ($this->menu as $m) {
            $m->enabled = FALSE;
            $m->allow_delete = FALSE;
            $m->users = [];
            $returnlist[] = $m;
        }

        $users = $this->user->all();
        /// colocamos a los administradores primero
        usort($users, function($a, $b) {
            if ($a->admin) {
                return -1;
            } else if ($b->admin) {
                return 1;
            }

            return 0;
        });

        /// completamos con los permisos de los usuarios
        foreach ($users as $user) {
            if ($user->admin) {
                foreach ($returnlist as $i => $value) {
                    $returnlist[$i]->users[$user->nick] = array(
                        'modify' => TRUE,
                        'delete' => TRUE,
                    );
                }
            } else {
                foreach ($returnlist as $i => $value) {
                    $returnlist[$i]->users[$user->nick] = array(
                        'modify' => FALSE,
                        'delete' => FALSE,
                    );
                }

                foreach ($user->get_accesses() as $a) {
                    foreach ($returnlist as $i => $value) {
                        if ($a->fs_page == $value->name) {
                            $returnlist[$i]->users[$user->nick]['modify'] = TRUE;
                            $returnlist[$i]->users[$user->nick]['delete'] = $a->allow_delete;
                            break;
                        }
                    }
                }
            }
        }

        /// ordenamos por nombre
        usort($returnlist, function($a, $b) {
            return strcmp($a->name, $b->name);
        });

        return $returnlist;
    }
}