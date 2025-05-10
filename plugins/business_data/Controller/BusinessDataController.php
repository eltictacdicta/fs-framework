<?php
/**
 * Controller for the business_data plugin
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 * Controller for business data management
 */
class BusinessDataController extends fs_controller
{
    public $empresa;
    public $paises;
    public $series;
    public $almacenes;
    public $agentes;

    public function __construct()
    {
        parent::__construct('business_data', 'Datos Empresariales', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        // Get data from models
        $this->empresa = new empresa();
        $this->paises = (new pais())->all();
        $this->series = (new serie())->all();
        $this->almacenes = (new almacen())->all();
        
        if (class_exists('agente')) {
            $this->agentes = (new agente())->all();
        } else {
            $this->agentes = [];
        }
        
        // Display info message
        $this->new_message('Este plugin contiene funcionalidades relacionadas con datos empresariales');
    }
}
