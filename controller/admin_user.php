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
 * Controlador para modificar el perfil del usuario.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_user extends fs_controller
{

    public $agente;
    public $allow_delete;
    public $allow_modify;
    public $user_log;
    public $suser;
    public $rol;
    public $user_roles;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Usuario', 'admin', TRUE, FALSE);
    }

    public function private_core()
    {
        $this->share_extensions();
        
        // Check if agente class exists before instantiating
        if (class_exists('agente')) {
            $this->agente = new agente();
        } else {
            $this->agente = null; // Set to null if class doesn't exist
        }

        $this->rol = new fs_rol();
        $this->user_roles = [];

        /// ¿El usuario tiene permiso para eliminar en esta página?
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on(__CLASS__);

        /// ¿El usuario tiene permiso para modificar en esta página?
        /// Con el sistema de roles, tener acceso a la página implica permiso de modificación.
        $this->allow_modify = $this->user->admin || $this->user->have_access_to(__CLASS__);

        $this->suser = FALSE;
        $snick = $this->request->query->get('snick');
        if ($snick !== null && $snick !== '') {
            $this->suser = $this->user->get((string) $snick);
        }

        if ($this->suser) {
            $this->page->title = $this->suser->nick;

            /// Cargamos los roles del usuario
            $this->user_roles = $this->rol->all_for_user($this->suser->nick);

            /// ¿Estamos modificando nuestro usuario?
            if ($this->suser->nick == $this->user->nick) {
                $this->allow_modify = TRUE;
                $this->allow_delete = FALSE;
            }

            if ($this->request->request->has('nnombre')) {
                $this->nuevo_empleado();
            } else if ($this->request->getMethod() === 'POST') {
                $this->modificar_user();

                if ($this->request->request->has('roles_form_present')) {
                    $this->aplicar_roles();
                }
            } else if (fs_filter_input_req('senabled')) {
                $this->desactivar_usuario();
            }

            /// ¿Estamos modificando nuestro usuario?
            if ($this->suser->nick == $this->user->nick) {
                $this->user = $this->suser;
            }

            /// si el usuario no tiene acceso a ninguna página, informamos sobre roles.
            if (!$this->suser->admin) {
                $sin_paginas = TRUE;
                foreach ($this->all_pages() as $p) {
                    if ($p->enabled) {
                        $sin_paginas = FALSE;
                        break;
                    }
                }
                if ($sin_paginas) {
                    $this->new_advice('Este usuario no tiene ningún rol asignado y por tanto'
                        . ' no podrá acceder a ninguna página. Asígnale un rol desde la'
                        . ' pestaña <b>Roles</b>.');
                }
            }

            $fslog = new fs_log();
            $this->user_log = $fslog->all_from($this->suser->nick);
        } else {
            $this->new_error_msg("Usuario no encontrado.", 'error', FALSE, FALSE);
        }
    }

    public function url()
    {
        if (!isset($this->suser)) {
            return parent::url();
        } else if ($this->suser) {
            return $this->suser->url();
        }

        return $this->page->url();
    }

    public function all_pages()
    {
        $returnlist = [];

        /// Obtenemos la lista de páginas. Todas
        foreach ($this->menu as $m) {
            $m->enabled = FALSE;
            $m->allow_delete = FALSE;
            $returnlist[] = $m;
        }

        /// Completamos con los permisos calculados desde los roles del usuario
        $allowed_pages = $this->suser->get_role_allowed_pages();
        foreach ($returnlist as $i => $value) {
            if (isset($allowed_pages[$value->name])) {
                $returnlist[$i]->enabled = TRUE;
                $returnlist[$i]->allow_delete = $allowed_pages[$value->name]['allow_delete'];
            }
        }

        /// ordenamos por nombre
        usort($returnlist, function($val1, $val2) {
            return strcmp($val1->name, $val2->name);
        });

        return $returnlist;
    }

    private function share_extensions()
    {
        foreach ($this->extensions as $ext) {
            if ($ext->type == 'css') {
                if (!file_exists($ext->text)) {
                    $ext->delete();
                }
            }
        }

        $extensions = array(
            array(
                'name' => 'cosmo',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-cosmo.min.css',
                'params' => ''
            ),
            array(
                'name' => 'darkly',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-darkly.min.css',
                'params' => ''
            ),
            array(
                'name' => 'flatly',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-flatly.min.css',
                'params' => ''
            ),
            array(
                'name' => 'sandstone',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-sandstone.min.css',
                'params' => ''
            ),
            array(
                'name' => 'united',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-united.min.css',
                'params' => ''
            ),
            array(
                'name' => 'yeti',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-yeti.min.css',
                'params' => ''
            ),
            array(
                'name' => 'lumen',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-lumen.min.css',
                'params' => ''
            ),
            array(
                'name' => 'paper',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-paper.min.css',
                'params' => ''
            ),
            array(
                'name' => 'simplex',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-simplex.min.css',
                'params' => ''
            ),
            array(
                'name' => 'spacelab',
                'page_from' => __CLASS__,
                'page_to' => __CLASS__,
                'type' => 'css',
                'text' => 'view/css/bootstrap-spacelab.min.css',
                'params' => ''
            ),
        );
        foreach ($extensions as $ext) {
            $fsext = new fs_extension($ext);
            $fsext->save();
        }
    }

    private function nuevo_empleado()
    {
        if (!class_exists('agente')) {
            $this->new_error_msg('No se puede crear un empleado porque la clase agente no está disponible. Se ha trasladado a un plugin.');
            return;
        }
        
        $age0 = new agente();
        $age0->codagente = $age0->get_new_codigo();
        $age0->nombre = $this->request->request->get('nnombre');
        $age0->apellidos = $this->request->request->get('napellidos');
        $age0->dnicif = $this->request->request->get('ndnicif');
        $age0->telefono = $this->request->request->get('ntelefono');
        $age0->email = strtolower((string) $this->request->request->get('nemail'));

        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede crear y asignar un empleado desde aquí.');
        } else if ($age0->save()) {
            $this->new_message("Empleado " . $age0->codagente . " guardado correctamente.");
            $this->suser->codagente = $age0->codagente;

            if ($this->suser->save()) {
                $this->new_message("Empleado " . $age0->codagente . " asignado correctamente.");
            } else {
                $this->new_error_msg("¡Imposible asignar el agente!");
            }
        } else {
            $this->new_error_msg("¡Imposible guardar el agente!");
        }
    }

    private function modificar_user()
    {
        if (FS_DEMO && $this->user->nick != $this->suser->nick) {
            $this->new_error_msg('En el modo <b>demo</b> sólo puedes modificar los datos de TU usuario.
        Esto es así para evitar malas prácticas entre usuarios que prueban la demo.');
        } else if (!$this->allow_modify) {
            $this->new_error_msg('No tienes permiso para modificar estos datos.');
        } else {
            $error = FALSE;
            $password_changed = FALSE;
            $spassword = trim((string) $this->request->request->get('spassword'));
            $spassword2 = trim((string) $this->request->request->get('spassword2'));
            if ($spassword !== '' || $spassword2 !== '') {
                if ($spassword !== '' && $spassword === $spassword2) {
                    if ($this->suser->set_password($spassword)) {
                        $this->suser->rotate_logkey();
                        $this->new_message('Se ha cambiado la contraseña del usuario ' . $this->suser->nick, TRUE, 'login', TRUE);
                        $password_changed = TRUE;
                    } else {
                        $this->new_error_msg('No se ha podido cambiar la contraseña.');
                        $error = TRUE;
                    }
                } else {
                    $this->new_error_msg('Las contraseñas no coinciden. No se ha guardado ningún cambio.');
                    $error = TRUE;
                }
            }

            /// Si la validación de contraseña falló, abortamos el guardado
            /// completo: ningún cambio debe persistir (atomicidad).
            if ($error) {
                /// Refetch para descartar cambios en memoria y que la vista
                /// muestre los valores reales de la BD.
                $this->suser = $this->user->get($this->suser->nick);
                return;
            }

            if ($this->request->request->has('email')) {
                $this->suser->email = strtolower((string) $this->request->request->get('email'));
            }

            if ($this->request->request->has('scodagente')) {
                $this->suser->codagente = NULL;
                $scodagente = (string) $this->request->request->get('scodagente');
                if ($scodagente !== '') {
                    $this->suser->codagente = $this->request->request->get('scodagente');
                }
            }

            /*
             * Propiedad admin: solamente un admin puede cambiarla.
             */
            if ($this->user->admin) {
                if ($this->user->nick != $this->suser->nick) {
                    $this->suser->admin = $this->request->request->has('sadmin');
                }
            }

            $this->suser->fs_page = NULL;
            if ($this->request->request->has('udpage')) {
                $this->suser->fs_page = $this->request->request->get('udpage');
            }

            if ($this->request->request->has('css')) {
                $this->suser->css = $this->request->request->get('css');
            }

            if ($this->suser->save()) {
                /// Los permisos ahora se gestionan exclusivamente por roles.
                /// No se modifican permisos individuales (fs_access) desde aquí.
                $this->new_message("Datos modificados correctamente.");
            } else {
                $this->new_error_msg("¡Imposible modificar los datos!");
            }
        }
    }

    private function desactivar_usuario()
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede activar o desactivar a un Usuario.');
        } else if ($this->user->nick == $this->suser->nick) {
            $this->new_error_msg('No se permite Activar/Desactivar a uno mismo.');
        } else {
            // Un usuario no se puede Activar/Desactivar a él mismo.
            $this->suser->enabled = (fs_filter_input_req('senabled') == 'TRUE');

            if ($this->suser->save()) {
                if ($this->suser->enabled) {
                    $this->new_message('Usuario activado correctamente.', TRUE, 'login', TRUE);
                } else {
                    $this->new_message('Usuario desactivado correctamente.', TRUE, 'login', TRUE);
                }
            } else {
                $this->new_error_msg('Error al Activar/Desactivar el Usuario');
            }
        }
    }

    /**
     * Aplica los roles seleccionados al usuario.
     * Los permisos se calculan dinámicamente desde los roles,
     * por lo que solo necesitamos gestionar la relación usuario-rol.
     */
    private function aplicar_roles()
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede modificar los roles de un usuario.');
            return;
        }

        if ($this->suser->admin) {
            $this->new_error_msg('Los administradores tienen acceso a todo, no necesitan roles.');
            return;
        }

        /// Primero eliminamos todos los roles actuales del usuario
        $fru = new fs_rol_user();
        $current_roles = $fru->all_from_user($this->suser->nick);
        foreach ($current_roles as $cr) {
            $cr->delete();
        }

        /// Ahora asignamos los roles seleccionados
        $roles_seleccionados = $this->request->request->all('roles');
        if ($roles_seleccionados) {
            foreach ($roles_seleccionados as $codrol) {
                $rol = $this->rol->get($codrol);
                if ($rol) {
                    $nuevo_fru = new fs_rol_user();
                    $nuevo_fru->codrol = $codrol;
                    $nuevo_fru->fs_user = $this->suser->nick;
                    $nuevo_fru->save();
                }
            }
        }

        /// Recargamos los roles del usuario
        $this->user_roles = $this->rol->all_for_user($this->suser->nick);
        $this->new_message('Roles actualizados correctamente.');
    }
}
