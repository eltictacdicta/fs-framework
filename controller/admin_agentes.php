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
 * Controlador de admin -> agentes.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_agentes extends fs_controller
{

    public $agente;
    public $modificar;
    public $nuevo_agente;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Agentes', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        $this->agente = new agente();
        $this->modificar = FALSE;
        $this->nuevo_agente = FALSE;

        if (filter_input(INPUT_POST, 'codagente') !== NULL) {
            $this->save_agente();
        } else if (filter_input(INPUT_GET, 'delete')) {
            $this->delete_agente();
        } else if (filter_input(INPUT_GET, 'cod')) {
            $this->modificar_agente();
        } else if (filter_input(INPUT_GET, 'nuevo')) {
            $this->nuevo_agente = TRUE;
        }
    }

    private function save_agente()
    {
        $data = $this->collectAgentFormData();

        if (filter_input(INPUT_POST, 'codagente') == '') {
            $this->createAgente($data);
            return;
        }

        $this->updateAgente($data);
    }

    private function collectAgentFormData(): array
    {
        $fields = [
            'codagente', 'nombre', 'apellidos', 'dnicif', 'email', 'telefono',
            'codpostal', 'provincia', 'ciudad', 'direccion', 'seg_social',
            'cargo', 'banco', 'f_nacimiento', 'f_alta', 'f_baja',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = fs_filter_input_req($field, '');
        }
        $data['porcomision'] = floatval(fs_filter_input_req('porcomision', '0'));
        return $data;
    }

    private function applyAgentData(object $agente_obj, array $data): void
    {
        $agente_obj->nombre = $data['nombre'];
        $agente_obj->apellidos = $data['apellidos'];
        $agente_obj->dnicif = $data['dnicif'];
        $agente_obj->email = $data['email'];
        $agente_obj->telefono = $data['telefono'];
        $agente_obj->codpostal = $data['codpostal'];
        $agente_obj->provincia = $data['provincia'];
        $agente_obj->ciudad = $data['ciudad'];
        $agente_obj->direccion = $data['direccion'];
        $agente_obj->seg_social = $data['seg_social'];
        $agente_obj->cargo = $data['cargo'];
        $agente_obj->banco = $data['banco'];
        $agente_obj->f_nacimiento = ($data['f_nacimiento'] != '') ? $data['f_nacimiento'] : NULL;
        $agente_obj->f_alta = ($data['f_alta'] != '') ? $data['f_alta'] : NULL;
        $agente_obj->f_baja = ($data['f_baja'] != '') ? $data['f_baja'] : NULL;
        $agente_obj->porcomision = $data['porcomision'];
    }

    private function createAgente(array $data): void
    {
        $agente_obj = new agente();
        $this->applyAgentData($agente_obj, $data);

        if ($agente_obj->save()) {
            $this->new_message('Agente ' . $this->no_html($agente_obj->codagente) . ' creado correctamente.');
            \FSFramework\Security\SafeRedirect::redirect($agente_obj->url(), 'index.php?page=admin_agentes');
            return;
        } else {
            $this->new_error_msg('Error al crear el agente.');
        }
    }

    private function updateAgente(array $data): void
    {
        $agente_obj = $this->agente->get($data['codagente']);
        if (!$agente_obj) {
            $this->new_error_msg('Agente no encontrado.');
            return;
        }

        $this->applyAgentData($agente_obj, $data);

        if ($agente_obj->save()) {
            $this->new_message('Agente ' . $this->no_html($agente_obj->codagente) . ' modificado correctamente.');
            \FSFramework\Security\SafeRedirect::redirect($agente_obj->url(), 'index.php?page=admin_agentes');
            return;
        } else {
            $this->new_error_msg('Error al modificar el agente.');
        }
    }

    private function delete_agente()
    {
        $codagente = filter_input(INPUT_GET, 'delete');
        $agente_obj = $this->agente->get($codagente);

        if ($agente_obj) {
            if (FS_DEMO) {
                $this->new_error_msg('En el modo <b>demo</b> no se pueden eliminar agentes.
                Esto es así para evitar malas prácticas entre usuarios que prueban la demo.');
            } else if ($agente_obj->delete()) {
                $this->new_message("Agente " . $agente_obj->codagente . " eliminado correctamente.");
            } else {
                $this->new_error_msg("¡Imposible eliminar al agente!");
            }
        } else {
            $this->new_error_msg("¡Agente no encontrado!");
        }
    }

    private function modificar_agente()
    {
        $codagente = filter_input(INPUT_GET, 'cod');
        $agente_obj = $this->agente->get($codagente);

        if ($agente_obj) {
            $this->agente = $agente_obj;
            $this->modificar = TRUE;
        } else {
            $this->new_error_msg('Agente no encontrado.');
        }
    }

    public function all_pages()
    {
        $returnlist = $this->initPageList();
        $users = $this->getSortedUsers();
        $this->applyUserPermissions($returnlist, $users);

        usort($returnlist, fn($a, $b) => strcmp($a->name, $b->name));

        return $returnlist;
    }

    private function initPageList(): array
    {
        $returnlist = [];
        foreach ($this->menu as $m) {
            $m->enabled = FALSE;
            $m->allow_delete = FALSE;
            $m->users = [];
            $returnlist[] = $m;
        }
        return $returnlist;
    }

    private function getSortedUsers(): array
    {
        $users = $this->user->all();
        usort($users, function ($a, $b) {
            if ($a->admin) {
                return -1;
            } else if ($b->admin) {
                return 1;
            }
            return 0;
        });
        return $users;
    }

    private function applyUserPermissions(array &$returnlist, array $users): void
    {
        foreach ($users as $user) {
            if ($user->admin) {
                $this->applyAdminPermissions($returnlist, $user);
            } else {
                $this->applyRegularUserPermissions($returnlist, $user);
            }
        }
    }

    private function applyAdminPermissions(array &$returnlist, $user): void
    {
        foreach ($returnlist as $i => $value) {
            $returnlist[$i]->users[$user->nick] = ['modify' => TRUE, 'delete' => TRUE];
        }
    }

    private function applyRegularUserPermissions(array &$returnlist, $user): void
    {
        foreach ($returnlist as $i => $value) {
            $returnlist[$i]->users[$user->nick] = ['modify' => FALSE, 'delete' => FALSE];
        }

        foreach ($user->get_accesses() as $a) {
            foreach ($returnlist as $i => $value) {
                if ($a->fs_page == $value->name) {
                    $returnlist[$i]->users[$user->nick]['modify'] = TRUE;
                    $returnlist[$i]->users[$user->nick]['delete'] = $a->allow_delete;
                    break;
                }
            }
        }
    }
}