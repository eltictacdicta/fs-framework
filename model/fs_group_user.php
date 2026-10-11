<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
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
 * Pertenencia de un usuario a un grupo organizativo, con una única función por
 * pareja `(codgrupo, fs_user)`: `miembro` o `responsable`.
 *
 * La pertenencia a un grupo NO concede permisos. Las funciones permitidas viven
 * en `FUNCIONES` (única fuente de verdad): habilitar una función futura es un
 * cambio de código/datos, nunca de esquema. El guard de valores fuera del
 * conjunto se ejecuta antes de cualquier SQL, de modo que "no persiste nada" se
 * garantiza en el modelo y no solo por una restricción de base de datos.
 */
class fs_group_user extends fs_model
{
    public const FUNCIONES = ['miembro', 'responsable'];

    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
    private const SQL_WHERE = ' WHERE ';

    public $codgrupo;
    public $fs_user;
    public $funcion;

    public function __construct($data = FALSE)
    {
        parent::__construct('fs_groups_users');
        if ($data) {
            $this->codgrupo = $data['codgrupo'];
            $this->fs_user = $data['fs_user'];
            $this->funcion = $data['funcion'] ?? 'miembro';
        } else {
            $this->codgrupo = NULL;
            $this->fs_user = NULL;
            $this->funcion = 'miembro';
        }
    }

    protected function install()
    {
        $group = new fs_group();
        $user = new fs_user();
        // Las instancias se crean para asegurar la carga de las clases y sus tablas

        return '';
    }

    public function exists()
    {
        if (is_null($this->codgrupo)) {
            return FALSE;
        }

        return $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "codgrupo = " . $this->var2str($this->codgrupo)
            . " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    public function save()
    {
        if (is_null($this->funcion) || $this->funcion === '') {
            $this->funcion = 'miembro';
        }

        if (!in_array($this->funcion, self::FUNCIONES, true)) {
            return FALSE;
        }

        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET funcion = " . $this->var2str($this->funcion)
                . " WHERE codgrupo = " . $this->var2str($this->codgrupo)
                . " AND fs_user = " . $this->var2str($this->fs_user) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (codgrupo,fs_user,funcion) VALUES "
                . "(" . $this->var2str($this->codgrupo)
                . "," . $this->var2str($this->fs_user)
                . "," . $this->var2str($this->funcion) . ");";
        }

        return (bool) $this->db->exec($sql);
    }

    public function delete()
    {
        return (bool) $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codgrupo = " . $this->var2str($this->codgrupo)
            . " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    public function all_from_group($codgrupo)
    {
        $list = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "codgrupo = " . $this->var2str($codgrupo)
            . " ORDER BY fs_user ASC;");
        if ($data) {
            foreach ($data as $a) {
                $list[] = new fs_group_user($a);
            }
        }

        return $list;
    }

    public function all_from_user($nick)
    {
        $list = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "fs_user = " . $this->var2str($nick)
            . " ORDER BY codgrupo ASC;");
        if ($data) {
            foreach ($data as $a) {
                $list[] = new fs_group_user($a);
            }
        }

        return $list;
    }

    public function responsables_from_group($codgrupo)
    {
        $list = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "codgrupo = " . $this->var2str($codgrupo)
            . " AND funcion = 'responsable' ORDER BY fs_user ASC;");
        if ($data) {
            foreach ($data as $a) {
                $list[] = new fs_group_user($a);
            }
        }

        return $list;
    }

    public function set_funcion($funcion = 'miembro')
    {
        if (is_null($funcion) || $funcion === '') {
            $funcion = 'miembro';
        }

        if (!in_array($funcion, self::FUNCIONES, true)) {
            return FALSE;
        }

        $this->funcion = $funcion;
        return (bool) $this->save();
    }
}
