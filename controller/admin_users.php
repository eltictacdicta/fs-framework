<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 * Controlador de admin -> users.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_users extends fs_controller
{

    public $agente;
    public $historial;
    public $rol;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Usuarios', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        // Check if agente class exists before instantiating
        if (class_exists('agente')) {
            $this->agente = new agente();
        } else {
            $this->agente = null; // Set to null if class doesn't exist
        }
        
        $this->rol = new fs_rol();

        if (filter_input(INPUT_POST, 'nnick')) {
            $this->add_user();
        } else if (filter_input(INPUT_GET, 'delete')) {
            $this->delete_user();
        } else if (filter_input(INPUT_POST, 'nrol')) {
            $this->add_rol();
        } else if (filter_input(INPUT_GET, 'delete_rol')) {
            $this->delete_rol();
        }

        /// cargamos el historial
        $fslog = new fs_log();
        $this->historial = $fslog->all_by('login');
    }

    private function add_user()
    {
        $nu = $this->user->get(filter_input(INPUT_POST, 'nnick'));
        if ($nu) {
            $this->new_error_msg('El usuario <a href="' . $nu->url() . '">ya existe</a>.');
        } else if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede crear usuarios.', 'login', TRUE, TRUE);
        } else {
            $nu = new fs_user();
            $nu->nick = filter_input(INPUT_POST, 'nnick');
            $nu->email = strtolower(filter_input(INPUT_POST, 'nemail'));

            if ($nu->set_password(filter_input(INPUT_POST, 'npassword'))) {
                $nu->admin = (bool) filter_input(INPUT_POST, 'nadmin');
                // Only set codagente if the agente class exists and a value was provided
                if (class_exists('agente') && filter_input(INPUT_POST, 'ncodagente') && filter_input(INPUT_POST, 'ncodagente') != '') {
                    $nu->codagente = filter_input(INPUT_POST, 'ncodagente');
                }

                if ($nu->save()) {
                    $this->new_message('Usuario ' . $nu->nick . ' creado correctamente.', TRUE, 'login', TRUE);

                    /// algún rol marcado
                    if (!$nu->admin && filter_input(INPUT_POST, 'roles', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) {
                        foreach (filter_input(INPUT_POST, 'roles', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY) as $codrol) {
                            $rol = $this->rol->get($codrol);
                            if ($rol) {
                                $fru = new fs_rol_user();
                                $fru->codrol = $codrol;
                                $fru->fs_user = $nu->nick;

                                if ($fru->save()) {
                                    foreach ($rol->get_accesses() as $p) {
                                        $a = new fs_access();
                                        $a->fs_page = $p->fs_page;
                                        $a->fs_user = $nu->nick;
                                        $a->allow_delete = $p->allow_delete;
                                        $a->save();
                                    }
                                }
                            }
                        }
                    }

                    Header('location: index.php?page=admin_user&snick=' . $nu->nick);
                } else {
                    $this->new_error_msg("¡Imposible guardar el usuario!");
                }
            }
        }
    }

    private function delete_user()
    {
        $nu = $this->user->get(filter_input(INPUT_GET, 'delete'));
        if ($nu) {
            if (FS_DEMO) {
                $this->new_error_msg('En el modo <b>demo</b> no se pueden eliminar usuarios.
               Esto es así para evitar malas prácticas entre usuarios que prueban la demo.');
            } else if (!$this->user->admin) {
                $this->new_error_msg("Solamente un administrador puede eliminar usuarios.", 'login', TRUE);
            } else if ($nu->delete()) {
                $this->new_message("Usuario " . $nu->nick . " eliminado correctamente.", TRUE, 'login', TRUE);
            } else {
                $this->new_error_msg("¡Imposible eliminar al usuario!");
            }
        } else {
            $this->new_error_msg("¡Usuario no encontrado!");
        }
    }

    private function add_rol()
    {
        $this->rol->codrol = filter_input(INPUT_POST, 'nrol');
        $this->rol->descripcion = filter_input(INPUT_POST, 'descripcion');

        if ($this->rol->save()) {
            $this->new_message('Datos guardados correctamente.');
            header('Location: ' . $this->rol->url());
        } else {
            $this->new_error_msg('Error al crear el rol.');
        }
    }

    private function delete_rol()
    {
        $rol = $this->rol->get(filter_input(INPUT_GET, 'delete_rol'));
        if ($rol) {
            if ($rol->delete()) {
                $this->new_message('Rol eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el rol #' . $rol->codrol);
            }
        } else {
            $this->new_error_msg('Rol no encontrado.');
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
