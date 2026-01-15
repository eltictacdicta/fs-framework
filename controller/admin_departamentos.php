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
 * Controlador para listar y gestionar departamentos.
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class admin_departamentos extends fs_controller
{

    public $allow_delete;
    public $departamento;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Departamentos', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        /// ¿El usuario tiene permiso para eliminar en esta página?
        $this->allow_delete = $this->user->admin;

        $this->departamento = new fs_departamento();

        if (filter_input(INPUT_POST, 'nnombre')) {
            $this->add_departamento();
        } else if (filter_input(INPUT_GET, 'delete')) {
            $this->delete_departamento();
        }
    }

    private function add_departamento()
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solamente un administrador puede crear departamentos.', 'login', TRUE, TRUE);
            return;
        }

        // El código se genera automáticamente con UUID
        $this->departamento->nombre = filter_input(INPUT_POST, 'nnombre');
        $this->departamento->descripcion = filter_input(INPUT_POST, 'ndescripcion');
        $this->departamento->activo = TRUE;
        $this->departamento->fecha_alta = date('Y-m-d');

        if ($this->departamento->save()) {
            $this->new_message('Departamento creado correctamente.');
            header('Location: ' . $this->departamento->url());
        } else {
            $this->new_error_msg('Error al crear el departamento.');
        }
    }

    private function delete_departamento()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar departamentos.');
            return;
        }

        $depto = $this->departamento->get(filter_input(INPUT_GET, 'delete'));
        if ($depto) {
            if ($depto->delete()) {
                $this->new_message('Departamento eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el departamento #' . $depto->coddepartamento);
            }
        } else {
            $this->new_error_msg('Departamento no encontrado.');
        }
    }

    public function all_departamentos()
    {
        return $this->departamento->all();
    }
}
