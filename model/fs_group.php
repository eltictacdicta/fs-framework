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
 * Grupo de usuarios: entidad organizativa identificada por un código
 * introducido por el humano (`codgrupo`), con una descripción.
 *
 * Los grupos son planos y organizativos: la pertenencia a un grupo NO concede
 * permisos. Los permisos siguen viviendo en `fs_rol` / `fs_rol_access`; este
 * modelo no participa en ninguna ruta de autorización.
 */
class fs_group extends fs_model
{
    public $codgrupo;
    public $descripcion;

    public function __construct($data = FALSE)
    {
        parent::__construct('fs_groups');
        if ($data) {
            $this->codgrupo = $data['codgrupo'];
            $this->descripcion = $data['descripcion'];
        } else {
            $this->codgrupo = NULL;
            $this->descripcion = NULL;
        }
    }

    protected function install()
    {
        return '';
    }

    public function url()
    {
        if (is_null($this->codgrupo)) {
            return 'index.php?page=admin_groups';
        }

        return 'index.php?page=admin_group&codgrupo=' . urlencode($this->codgrupo);
    }

    public function get($codgrupo)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($codgrupo) . ";");
        if ($data) {
            return new fs_group($data[0]);
        }

        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->codgrupo)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";");
    }

    public function save()
    {
        $this->descripcion = $this->no_html($this->descripcion);

        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET descripcion = " . $this->var2str($this->descripcion)
                . " WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (codgrupo,descripcion) VALUES "
                . "(" . $this->var2str($this->codgrupo)
                . "," . $this->var2str($this->descripcion) . ");";
        }

        return (bool) $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = "DELETE FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";";
        return (bool) $this->db->exec($sql);
    }

    public function all()
    {
        $lista = [];

        $sql = "SELECT * FROM " . $this->table_name . " ORDER BY descripcion ASC, codgrupo ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_group($d);
            }
        }

        return $lista;
    }

    /**
     * Devuelve los grupos a los que pertenece el usuario indicado.
     *
     * @param string $nick
     * @return array
     */
    public function all_for_user($nick)
    {
        $lista = [];

        // Instanciar el modelo para asegurar que la tabla existe
        $gu = new fs_group_user();

        $sql = "SELECT * FROM " . $this->table_name . " WHERE codgrupo IN "
            . "(SELECT codgrupo FROM " . $gu->table_name() . " WHERE fs_user = " . $this->var2str($nick) . ");";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_group($d);
            }
        }

        return $lista;
    }

    /**
     * Devuelve las pertenencias (miembros) de este grupo.
     *
     * @return array
     */
    public function get_users()
    {
        $gu = new fs_group_user();
        return $gu->all_from_group($this->codgrupo);
    }

    /**
     * Devuelve las pertenencias marcadas como responsable de este grupo.
     *
     * @return array
     */
    public function get_responsables()
    {
        $gu = new fs_group_user();
        return $gu->responsables_from_group($this->codgrupo);
    }
}
