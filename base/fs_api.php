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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Description of fs_api
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_api
{

    /**
     * 
     * @return string
     */
    public function run()
    {
        $function_name = fs_filter_input_req('f');
        $version = fs_filter_input_req('v');

        if (!$version) {
            return 'Version de la API de FacturaScripts ausente. Actualiza el cliente.';
        } else if ($version != '2') {
            return 'Version de la API de FacturaScripts incorrecta. Actualiza el cliente.';
        } else if (!$function_name) {
            return 'Ninguna funcion ejecutada.';
        }

        return $this->execute($function_name);
    }

    /**
     * 
     * @return string
     */
    private function get_last_activity()
    {
        $last_activity = 0;

        $user_model = new fs_user();
        foreach ($user_model->all() as $user) {
            $time = empty($user->last_login) ? 0 : strtotime($user->last_login . ' ' . $user->last_login_time);
            if ($time > $last_activity) {
                $last_activity = $time;
            }
        }

        return date('Y-m-d H:i:s', $last_activity);
    }

    /**
     * 
     * @param string $function_name
     *
     * @return string
     */
    private function execute($function_name)
    {
        $allowed_functions = ['lastactivity']; // Lista de funciones permitidas
        $fsext = new fs_extension();

        // Agregar funciones de extensiones a la lista permitida
        foreach ($fsext->all_4_type('api') as $ext) {
            $allowed_functions[] = $ext->text;
        }

        // Agregar funciones API del plugin api_auth directamente
        $api_auth_functions = ['api_login', 'api_logout', 'api_validate_token', 'api_user_info', 'api_is_admin', 'api_has_access', 'api_get_pages'];
        $allowed_functions = array_merge($allowed_functions, $api_auth_functions);

        // Verificar si la función solicitada está en la lista permitida
        if (in_array($function_name, $allowed_functions)) {
            try {
                if ($function_name == 'lastactivity') {
                    return $this->get_last_activity();
                } else {
                    // Llamar a la función de extensión
                    return call_user_func($function_name);
                }
            } catch (Exception $exception) {
                echo 'ERROR: ' . $exception->getMessage();
            }
        }

        return 'Ninguna funcion API ejecutada.';
    }
}
