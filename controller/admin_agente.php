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
 * Controlador de admin -> agente (individual).
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_agente extends fs_controller
{

    public $agente;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Agente', 'admin', TRUE, FALSE);
        error_log("admin_agente constructor called - show_on_menu should be FALSE");
    }

    protected function private_core()
    {
        $this->agente = FALSE;

        // Primero verificar si hay un POST (guardar agente)
        if (filter_input(INPUT_POST, 'codagente')) {
            $cod = filter_input(INPUT_POST, 'codagente');
        } else {
            $cod = filter_input(INPUT_GET, 'cod');
        }

        if ($cod) {
            $agente_obj = new agente();
            $this->agente = $agente_obj->get($cod);

            if ($this->agente) {
                if (filter_input(INPUT_POST, 'codagente')) {
                    $this->save_agente();
                }
            } else {
                $this->new_error_msg('Agente no encontrado.');
            }
        } else {
            $this->new_error_msg('Código de agente no proporcionado.');
        }
    }

    private function save_agente()
    {
        $agente_data = array(
            'codagente' => filter_input(INPUT_POST, 'codagente'),
            'nombre' => filter_input(INPUT_POST, 'nombre'),
            'apellidos' => filter_input(INPUT_POST, 'apellidos'),
            'dnicif' => filter_input(INPUT_POST, 'dnicif'),
            'email' => filter_input(INPUT_POST, 'email'),
            'telefono' => filter_input(INPUT_POST, 'telefono'),
            'codpostal' => filter_input(INPUT_POST, 'codpostal'),
            'provincia' => filter_input(INPUT_POST, 'provincia'),
            'ciudad' => filter_input(INPUT_POST, 'ciudad'),
            'direccion' => filter_input(INPUT_POST, 'direccion'),
            'seg_social' => filter_input(INPUT_POST, 'seg_social'),
            'cargo' => filter_input(INPUT_POST, 'cargo'),
            'banco' => filter_input(INPUT_POST, 'banco'),
            'f_nacimiento' => filter_input(INPUT_POST, 'f_nacimiento'),
            'f_alta' => filter_input(INPUT_POST, 'f_alta'),
            'f_baja' => filter_input(INPUT_POST, 'f_baja'),
            'porcomision' => floatval(filter_input(INPUT_POST, 'porcomision'))
        );

        if ($this->agente) {
            $this->agente->nombre = $agente_data['nombre'];
            $this->agente->apellidos = $agente_data['apellidos'];
            $this->agente->dnicif = $agente_data['dnicif'];
            $this->agente->email = $agente_data['email'];
            $this->agente->telefono = $agente_data['telefono'];
            $this->agente->codpostal = $agente_data['codpostal'];
            $this->agente->provincia = $agente_data['provincia'];
            $this->agente->ciudad = $agente_data['ciudad'];
            $this->agente->direccion = $agente_data['direccion'];
            $this->agente->seg_social = $agente_data['seg_social'];
            $this->agente->cargo = $agente_data['cargo'];
            $this->agente->banco = $agente_data['banco'];
            
            // Convertir fechas vacías a NULL
            $this->agente->f_nacimiento = empty($agente_data['f_nacimiento']) ? NULL : $agente_data['f_nacimiento'];
            $this->agente->f_alta = empty($agente_data['f_alta']) ? NULL : $agente_data['f_alta'];
            $this->agente->f_baja = empty($agente_data['f_baja']) ? NULL : $agente_data['f_baja'];
            
            $this->agente->porcomision = $agente_data['porcomision'];

            if ($this->agente->save()) {
                $this->new_message('Agente ' . $this->agente->codagente . ' modificado correctamente.');
            } else {
                $this->new_error_msg('Error al modificar el agente.');
            }
        } else {
            $this->new_error_msg('Agente no encontrado.');
        }
    }
}