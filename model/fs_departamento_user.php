<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025       Javier Trujillo      <mistertekcom at gmail.com>
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
 * Define la relación entre un usuario y un departamento.
 * Incluye un flag para indicar si el usuario es administrador del departamento.
 *
 * @author Javier Trujillo <mistertekcom at gmail.com>
 */
class fs_departamento_user extends fs_model
{

    public $coddepartamento;
    public $fs_user;
    public $es_admin;
    public $fecha_alta;

    public function __construct($data = FALSE)
    {
        parent::__construct('fs_departamentos_users');
        if ($data) {
            $this->coddepartamento = $data['coddepartamento'];
            $this->fs_user = $data['fs_user'];
            $this->es_admin = $this->str2bool($data['es_admin']);
            $this->fecha_alta = isset($data['fecha_alta']) ? $data['fecha_alta'] : date('Y-m-d');
        } else {
            $this->coddepartamento = NULL;
            $this->fs_user = NULL;
            $this->es_admin = FALSE;
            $this->fecha_alta = date('Y-m-d');
        }
    }

    protected function install()
    {
        return '';
    }

    public function exists()
    {
        if (is_null($this->coddepartamento) || is_null($this->fs_user)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name
                . " WHERE coddepartamento = " . $this->var2str($this->coddepartamento)
                . " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    public function get($coddepartamento, $fs_user)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE coddepartamento = " . $this->var2str($coddepartamento)
            . " AND fs_user = " . $this->var2str($fs_user) . ";");
        if ($data) {
            return new fs_departamento_user($data[0]);
        }

        return FALSE;
    }

    public function save()
    {
        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET "
                . "es_admin = " . $this->var2str($this->es_admin)
                . ", fecha_alta = " . $this->var2str($this->fecha_alta)
                . " WHERE coddepartamento = " . $this->var2str($this->coddepartamento)
                . " AND fs_user = " . $this->var2str($this->fs_user) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (coddepartamento,fs_user,es_admin,fecha_alta) VALUES "
                . "(" . $this->var2str($this->coddepartamento)
                . "," . $this->var2str($this->fs_user)
                . "," . $this->var2str($this->es_admin)
                . "," . $this->var2str($this->fecha_alta) . ");";
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name .
                " WHERE coddepartamento = " . $this->var2str($this->coddepartamento) .
                " AND fs_user = " . $this->var2str($this->fs_user) . ";");
    }

    /**
     * Devuelve todos los usuarios de un departamento.
     * @param string $coddepartamento
     * @return array
     */
    public function all_from_departamento($coddepartamento)
    {
        $lista = [];

        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE coddepartamento = " . $this->var2str($coddepartamento) . " ORDER BY fs_user ASC;");
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento_user($d);
            }
        }

        return $lista;
    }

    /**
     * Devuelve solo los administradores de un departamento.
     * @param string $coddepartamento
     * @return array
     */
    public function admins_from_departamento($coddepartamento)
    {
        $lista = [];

        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE coddepartamento = " . $this->var2str($coddepartamento) . " AND es_admin = TRUE ORDER BY fs_user ASC;");
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento_user($d);
            }
        }

        return $lista;
    }

    /**
     * Devuelve todos los departamentos de un usuario.
     * @param string $nick
     * @return array
     */
    public function all_from_user($nick)
    {
        $lista = [];

        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE fs_user = " . $this->var2str($nick) . " ORDER BY coddepartamento ASC;");
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento_user($d);
            }
        }

        return $lista;
    }

    /**
     * Comprueba si un usuario es administrador de un departamento.
     * @param string $coddepartamento
     * @param string $nick
     * @return bool
     */
    public function is_admin($coddepartamento, $nick)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE coddepartamento = " . $this->var2str($coddepartamento)
            . " AND fs_user = " . $this->var2str($nick)
            . " AND es_admin = TRUE;");
        return $data ? TRUE : FALSE;
    }
}
