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
 * Define un departamento para agrupar usuarios.
 *
 * @author Javier Trujillo <mistertekcom at gmail.com>
 */
class fs_departamento extends fs_model
{

    public $coddepartamento;
    public $nombre;
    public $descripcion;
    public $activo;
    public $fecha_alta;

    public function __construct($data = FALSE)
    {
        parent::__construct('fs_departamentos');
        if ($data) {
            $this->coddepartamento = $data['coddepartamento'];
            $this->nombre = $data['nombre'];
            $this->descripcion = $data['descripcion'];
            $this->activo = $this->str2bool($data['activo']);
            $this->fecha_alta = $data['fecha_alta'];
        } else {
            $this->coddepartamento = NULL;
            $this->nombre = NULL;
            $this->descripcion = NULL;
            $this->activo = TRUE;
            $this->fecha_alta = date('Y-m-d');
        }
    }

    /**
     * Genera un UUID v4 para usar como código de departamento.
     * @return string
     */
    public function generate_uuid()
    {
        // Generar UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Genera un nuevo código de departamento único.
     * @return string
     */
    public function get_new_codigo()
    {
        return $this->generate_uuid();
    }

    protected function install()
    {
        return '';
    }

    public function url()
    {
        if (is_null($this->coddepartamento)) {
            return 'index.php?page=admin_departamentos';
        }

        return 'index.php?page=admin_departamento&coddepartamento=' . urlencode($this->coddepartamento);
    }

    public function get($coddepartamento)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE coddepartamento = " . $this->var2str($coddepartamento) . ";");
        if ($data) {
            return new fs_departamento($data[0]);
        }

        return FALSE;
    }

    /**
     * Devuelve la lista de usuarios del departamento.
     * @return array
     */
    public function get_users()
    {
        $du = new fs_departamento_user();
        return $du->all_from_departamento($this->coddepartamento);
    }

    /**
     * Devuelve la lista de administradores del departamento.
     * @return array
     */
    public function get_admins()
    {
        $du = new fs_departamento_user();
        return $du->admins_from_departamento($this->coddepartamento);
    }

    /**
     * Devuelve el número de usuarios en el departamento.
     * @return int
     */
    public function count_users()
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM fs_departamentos_users WHERE coddepartamento = " . $this->var2str($this->coddepartamento) . ";");
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Devuelve el número de administradores del departamento.
     * @return int
     */
    public function count_admins()
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM fs_departamentos_users WHERE coddepartamento = " . $this->var2str($this->coddepartamento) . " AND es_admin = TRUE;");
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    public function exists()
    {
        if (is_null($this->coddepartamento)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE coddepartamento = " . $this->var2str($this->coddepartamento) . ";");
    }

    public function test()
    {
        // Generar UUID automáticamente si no hay código (antes del trim para evitar null)
        if (is_null($this->coddepartamento) || $this->coddepartamento === '') {
            $this->coddepartamento = $this->get_new_codigo();
        }
        
        $this->coddepartamento = trim($this->coddepartamento);
        $this->nombre = $this->no_html($this->nombre);
        $this->descripcion = is_null($this->descripcion) ? '' : $this->no_html($this->descripcion);

        if (strlen($this->coddepartamento) < 1 || strlen($this->coddepartamento) > 36) {
            $this->new_error_msg("Código de departamento no válido. Debe tener entre 1 y 36 caracteres.");
            return FALSE;
        }

        if (strlen($this->nombre) < 1 || strlen($this->nombre) > 100) {
            $this->new_error_msg("Nombre de departamento no válido. Debe tener entre 1 y 100 caracteres.");
            return FALSE;
        }

        return TRUE;
    }

    public function save()
    {
        if (!$this->test()) {
            return FALSE;
        }

        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET "
                . "nombre = " . $this->var2str($this->nombre)
                . ", descripcion = " . $this->var2str($this->descripcion)
                . ", activo = " . $this->var2str($this->activo)
                . ", fecha_alta = " . $this->var2str($this->fecha_alta)
                . " WHERE coddepartamento = " . $this->var2str($this->coddepartamento) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (coddepartamento,nombre,descripcion,activo,fecha_alta) VALUES "
                . "(" . $this->var2str($this->coddepartamento)
                . "," . $this->var2str($this->nombre)
                . "," . $this->var2str($this->descripcion)
                . "," . $this->var2str($this->activo)
                . "," . $this->var2str($this->fecha_alta) . ");";
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = "DELETE FROM " . $this->table_name . " WHERE coddepartamento = " . $this->var2str($this->coddepartamento) . ";";
        return $this->db->exec($sql);
    }

    public function all()
    {
        $lista = [];

        $sql = "SELECT * FROM " . $this->table_name . " ORDER BY nombre ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento($d);
            }
        }

        return $lista;
    }

    public function all_activos()
    {
        $lista = [];

        $sql = "SELECT * FROM " . $this->table_name . " WHERE activo = TRUE ORDER BY nombre ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento($d);
            }
        }

        return $lista;
    }

    public function all_for_user($nick)
    {
        $lista = [];

        $sql = "SELECT * FROM " . $this->table_name . " WHERE coddepartamento IN "
            . "(SELECT coddepartamento FROM fs_departamentos_users WHERE fs_user = " . $this->var2str($nick) . ");";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento($d);
            }
        }

        return $lista;
    }

    /**
     * Devuelve los departamentos donde el usuario es administrador.
     * @param string $nick
     * @return array
     */
    public function all_admin_for_user($nick)
    {
        $lista = [];

        $sql = "SELECT * FROM " . $this->table_name . " WHERE coddepartamento IN "
            . "(SELECT coddepartamento FROM fs_departamentos_users WHERE fs_user = " . $this->var2str($nick) . " AND es_admin = TRUE);";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $lista[] = new fs_departamento($d);
            }
        }

        return $lista;
    }
}
