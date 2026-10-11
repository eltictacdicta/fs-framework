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
 * Controlador de admin -> editar un grupo de usuarios.
 *
 * Página solo-administrador que edita la descripción de un grupo y guarda sus
 * pertenencias (con una única función por usuario) en un único POST. No es una
 * entrada del menú: se llega desde el listado admin_groups. Los grupos NO
 * conceden permisos: la autorización sigue viviendo en fs_rol / fs_rol_access.
 */
#[\FSFramework\Attribute\AdminOnly]
class admin_group extends fs_controller
{
    /** @var fs_group|false Grupo seleccionado por ?codgrupo= */
    public $group;

    /** @var bool ¿Puede el usuario actual eliminar el grupo? */
    public $allow_delete;

    /** @var array Funciones de pertenencia permitidas (fs_group_user::FUNCIONES) */
    public $funciones = [];

    public function __construct()
    {
        // El 4º argumento ($admin) está obsoleto y se ignora; el 5º (FALSE)
        // declara que este editor NO es una entrada del menú (como admin_rol).
        parent::__construct(__CLASS__, 'Editar grupo', 'admin', FALSE, FALSE);
    }

    protected function private_core()
    {
        // Eliminar un grupo es una operación sensible: solo administradores.
        $this->allow_delete = $this->user->admin;
        $this->funciones = fs_group_user::FUNCIONES;

        if (fs_filter_input_req('codgrupo')) {
            $this->group = (new fs_group())->get(fs_filter_input_req('codgrupo'));
        }

        if ($this->group && $this->request->isMethod('POST')) {
            $this->modify();
        } elseif (!$this->group) {
            $this->new_error_msg('Grupo no encontrado.');
        }
    }

    /**
     * Lista todos los usuarios anotando su pertenencia al grupo.
     *
     * Cada fs_user recibe `included` (¿es miembro?) y `funcion` (su función
     * actual, por defecto 'miembro'). Mismo patrón que admin_rol::all_users().
     *
     * @return array
     */
    public function all_users()
    {
        $returnlist = [];

        foreach ($this->user->all() as $u) {
            $u->included = FALSE;
            $u->funcion = 'miembro';
            $returnlist[] = $u;
        }

        $users = $this->group->get_users();
        foreach ($returnlist as $i => $value) {
            foreach ($users as $a) {
                if ($value->nick == $a->fs_user) {
                    $returnlist[$i]->included = TRUE;
                    $returnlist[$i]->funcion = $a->funcion;
                    break;
                }
            }
        }

        return $returnlist;
    }

    /**
     * Guarda la descripción y las pertenencias del grupo en un único POST.
     *
     * La descripción solo se toca si el campo viajó en la petición (seguridad
     * ante POST parciales). Las pertenencias se procesan solo si el marcador
     * oculto `members_form_present` está presente: así un POST parcial no borra
     * todas las pertenencias por ausencia de casillas.
     */
    private function modify()
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede modificar los grupos.', 'login', TRUE, TRUE);
            return;
        }

        $descripcion = filter_input(INPUT_POST, 'descripcion');
        if ($descripcion !== null) {
            $this->group->descripcion = $descripcion;
        }

        if (!$this->group->save()) {
            $this->new_error_msg('Error al guardar los datos.');
            return;
        }

        if ($this->request->request->has('members_form_present')) {
            $members = filter_input(INPUT_POST, 'member', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
            $funciones = filter_input(INPUT_POST, 'funcion', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY) ?: [];

            foreach ($this->all_users() as $u) {
                /**
                 * Creamos un objeto fs_group_user con los datos del grupo y el
                 * usuario. Si está marcado como miembro guardamos con su
                 * función; si no, eliminamos la pertenencia. Así no comprobamos
                 * uno a uno si ya existía: eso lo hace el modelo.
                 */
                $a = new fs_group_user(array('codgrupo' => $this->group->codgrupo, 'fs_user' => $u->nick));

                if ($members && in_array($u->nick, $members)) {
                    $funcion = $funciones[$u->nick] ?? 'miembro';
                    if (!in_array($funcion, $this->funciones, true)) {
                        $funcion = 'miembro';
                    }

                    $a->funcion = $funcion;
                    $a->save();
                } else {
                    $a->delete();
                }
            }
        }

        $this->new_message('Datos guardados.');
    }
}
