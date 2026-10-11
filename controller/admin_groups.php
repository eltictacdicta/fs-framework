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
 * Controlador de admin -> grupos de usuarios.
 *
 * Página solo-administrador que lista los grupos organizativos, permite crear
 * uno nuevo por su código y eliminarlos. Los grupos NO conceden permisos: la
 * autorización sigue viviendo en fs_rol / fs_rol_access.
 */
#[\FSFramework\Attribute\AdminOnly]
class admin_groups extends fs_controller
{
    public $groups = [];

    /** @var bool ¿Puede el usuario actual eliminar grupos? */
    public $allow_delete;

    public function __construct()
    {
        // El 4º argumento ($admin) está obsoleto y se ignora; el 5º (TRUE)
        // declara esta página como entrada propia del menú de administración.
        parent::__construct(__CLASS__, 'Grupos', 'admin', FALSE, TRUE);
    }

    protected function private_core()
    {
        // Borrar un grupo es una operación sensible: solo administradores.
        $this->allow_delete = $this->user->admin;

        $fs_group = new fs_group();

        if (filter_input(INPUT_POST, 'ncodgrupo')) {
            $this->add_group($fs_group);
        } elseif (filter_input(INPUT_POST, 'delete_grupo')) {
            $this->delete_group($fs_group);
        }

        $this->groups = $fs_group->all();
    }

    /**
     * Crea un grupo a partir del código introducido en el formulario.
     *
     * @param fs_group $fs_group modelo vacío reutilizado para leer y escribir
     */
    private function add_group($fs_group)
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede crear grupos.', 'login', TRUE, TRUE);
            return;
        }

        $codgrupo = filter_input(INPUT_POST, 'ncodgrupo');

        // El código lo introduce el humano: lo validamos antes de tocar la BD.
        // fs_groups.codgrupo es varchar(20) y se usa como identificador plano.
        if (
            !is_string($codgrupo)
            || $codgrupo === ''
            || mb_strlen($codgrupo) > 20
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $codgrupo)
        ) {
            $this->new_error_msg('El código del grupo debe ser una cadena de 1 a 20 caracteres (letras, números, guiones y guiones bajos).');
            return;
        }

        // Antes de crear comprobamos que no exista para poder informar en lugar
        // de sobrescribir en silencio.
        $existing = $fs_group->get($codgrupo);
        if ($existing) {
            $this->new_error_msg('El grupo <b>' . $this->no_html($codgrupo) . '</b> ya existe.');
            return;
        }

        $group = new fs_group(array(
            'codgrupo' => $codgrupo,
            'descripcion' => filter_input(INPUT_POST, 'descripcion'),
        ));

        if ($group->save()) {
            $this->new_message('Grupo ' . $this->no_html($group->codgrupo) . ' creado correctamente.');
            header('Location: ' . $group->url());
            exit;
        } else {
            $this->new_error_msg('Error al crear el grupo.');
        }
    }

    /**
     * Elimina un grupo por su código.
     *
     * @param fs_group $fs_group
     */
    private function delete_group($fs_group)
    {
        // Borrado destructivo (ON DELETE CASCADE): exigimos un token CSRF válido
        // aunque FS_CSRF_SOFT esté activo, para que nunca se ejecute sin él.
        if (!$this->requireCsrf()) {
            return;
        }

        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede eliminar grupos.', 'login', TRUE, TRUE);
            return;
        }

        $group = $fs_group->get(filter_input(INPUT_POST, 'delete_grupo'));
        if ($group) {
            if ($group->delete()) {
                $this->new_message('Grupo eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el grupo #' . $this->no_html($group->codgrupo));
            }
        } else {
            $this->new_error_msg('Grupo no encontrado.');
        }
    }
}
