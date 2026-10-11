<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2016       Joe Nilson             <joenilson at gmail.com>
 * Copyright (C) 2017-2018  Carlos García Gómez    <neorazorx at gmail.com>
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
 * Define la relación entre un usuario y un rol.
 *
 * @author Joe Nilson            <joenilson at gmail.com>
 * @author Carlos García Gómez   <neorazorx at gmail.com>
 */
class fs_rol_user extends fs_model
{
    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
    private const SQL_WHERE = ' WHERE ';

    public $codrol;
    public $fs_user;

    /**
     * Marca de responsable de la pertenencia. NO concede permisos: es solo un
     * marcador informativo para usos futuros.
     * @var boolean
     */
    public $responsable;

    public function __construct($data = FALSE)
    {
        parent::__construct('fs_roles_users');
        if ($data) {
            $this->codrol = $data['codrol'];
            $this->fs_user = $data['fs_user'];
            $this->responsable = isset($data['responsable']) ? $this->str2bool($data['responsable']) : FALSE;
        } else {
            $this->codrol = NULL;
            $this->fs_user = NULL;
            $this->responsable = FALSE;
        }
    }

    protected function install()
    {
        $rol = new fs_rol();
        $user = new fs_user();
        // Las instancias se crean para asegurar la carga de las clases y sus tablas

        return '';
    }

    public function exists()
    {
        if (is_null($this->codrol)) {
            return FALSE;
        }

        return $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "codrol = " . $this->var2str($this->codrol)
                . " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    public function save()
    {
        /// Upsert: la marca de responsable debe poder actualizarse sobre una
        /// pertenencia ya existente, y los llamadores antiguos que solo fijan
        /// codrol+fs_user siguen funcionando (responsable por defecto FALSE).
        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET responsable = " . $this->var2str($this->responsable)
                . " WHERE codrol = " . $this->var2str($this->codrol)
                . " AND fs_user = " . $this->var2str($this->fs_user) . ";";

            return $this->db->exec($sql);
        }

        $sql = "INSERT INTO " . $this->table_name . " (codrol,fs_user,responsable) VALUES "
            . "(" . $this->var2str($this->codrol)
            . "," . $this->var2str($this->fs_user)
            . "," . $this->var2str($this->responsable) . ");";

        return $this->db->exec($sql);
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name .
                " WHERE codrol = " . $this->var2str($this->codrol) .
                " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    public function all_from_rol($codrol)
    {
        $accesslist = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name . self::SQL_WHERE . "codrol = " . $this->var2str($codrol) . ";");
        if ($data) {
            foreach ($data as $a) {
                $accesslist[] = new fs_rol_user($a);
            }
        }

        return $accesslist;
    }

    /**
     * Pertenencia con la marca de responsable activa para el rol indicado.
     * Un rol puede tener varios responsables.
     *
     * @param string $codrol
     * @return fs_rol_user[]
     */
    public function responsables_from_rol($codrol)
    {
        $list = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name
            . self::SQL_WHERE . "codrol = " . $this->var2str($codrol)
            . " AND responsable = " . $this->var2str(TRUE) . ";");
        if ($data) {
            foreach ($data as $a) {
                $list[] = new fs_rol_user($a);
            }
        }

        return $list;
    }

    public function all_from_user($nick)
    {
        $list = [];

        $data = $this->db->select(self::SQL_SELECT_ALL_FROM . $this->table_name . self::SQL_WHERE . "fs_user = " . $this->var2str($nick) . ";");
        if ($data) {
            foreach ($data as $a) {
                $list[] = new fs_rol_user($a);
            }
        }

        return $list;
    }
}
