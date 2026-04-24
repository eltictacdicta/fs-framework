<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2015-2019 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 * Class to manage FacturaScripts settings.
 */
class fs_settings
{
    /**
     * Path for system logo storage
     */
    private const SYSTEM_LOGO_PATH = 'images/system_logo';

    /**
     * Allowed logo extensions
     */
    private const ALLOWED_LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg'];

    /**
     * Get a setting value from config2
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $GLOBALS['config2'][$key] ?? $default;
    }

    /**
     * Set a setting value in config2
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $GLOBALS['config2'][$key] = $value;
    }

    /**
     * Check if a setting exists
     *
     * @param string $key Setting key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($GLOBALS['config2'][$key]);
    }

    /**
     * Remove a setting from config2
     *
     * @param string $key Setting key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($GLOBALS['config2'][$key]);
    }

    /**
     * Get the system logo URL
     *
     * Priority:
        * 1. Configured system logo in settings if the file exists in FS_MYDOCS
        * 2. Stored system logo files using self::SYSTEM_LOGO_PATH and self::ALLOWED_LOGO_EXTENSIONS in FS_MYDOCS
        * 3. Legacy images/logo.png or images/logo.jpg in FS_MYDOCS
     *
        * @return string|null Logo URL or null when no system logo exists
     */
    public function getSystemLogoUrl(): ?string
    {
        $configured = $this->get('system_logo');
        if ($configured && file_exists(FS_MYDOCS . $configured)) {
            return $configured;
        }

        foreach (self::ALLOWED_LOGO_EXTENSIONS as $ext) {
            $path = self::SYSTEM_LOGO_PATH . '.' . $ext;
            if (file_exists(FS_MYDOCS . $path)) {
                return $path;
            }
        }

        if (file_exists(FS_MYDOCS . 'images/logo.png')) {
            return 'images/logo.png';
        }

        if (file_exists(FS_MYDOCS . 'images/logo.jpg')) {
            return 'images/logo.jpg';
        }

        return null;
    }

    /**
     * Get the full system logo URL with FS_PATH prefix
     *
     * @return string|null Full URL or null if no logo
     */
    public function getSystemLogoFullUrl(): ?string
    {
        $logoUrl = $this->getSystemLogoUrl();
        if ($logoUrl === null) {
            return null;
        }

        $basePath = defined('FS_PATH') ? rtrim((string) constant('FS_PATH'), '/') : '';
        return $basePath . '/' . ltrim($logoUrl, '/');
    }

    /**
     * Save system logo from uploaded file
     *
     * @param array $uploadedFile $_FILES array element
     * @return array{success: bool, error?: string, path?: string}
     */
    public function saveSystemLogo(array $uploadedFile): array
    {
        if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            return ['success' => false, 'error' => 'No se ha subido ningún archivo'];
        }

        $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_LOGO_EXTENSIONS, true)) {
            return ['success' => false, 'error' => 'Extensión no permitida. Use: ' . implode(', ', self::ALLOWED_LOGO_EXTENSIONS)];
        }

        $mimeType = mime_content_type($uploadedFile['tmp_name']);
        $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            return ['success' => false, 'error' => 'Tipo de archivo no permitido'];
        }

        $imagesDirectory = FS_MYDOCS . 'images';
        if (!is_dir($imagesDirectory) && !mkdir($imagesDirectory, 0755, true) && !is_dir($imagesDirectory)) {
            error_log('Unable to create system logo directory: ' . $imagesDirectory);
            return ['success' => false, 'error' => 'Error al preparar el directorio de logos'];
        }

        $this->deleteSystemLogo();

        $targetPath = self::SYSTEM_LOGO_PATH . '.' . $extension;
        $fullPath = FS_MYDOCS . $targetPath;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $fullPath)) {
            return ['success' => false, 'error' => 'Error al guardar el archivo'];
        }

        $this->set('system_logo', $targetPath);
        $this->save();

        return ['success' => true, 'path' => $targetPath];
    }

    /**
     * Delete the system logo
     *
     * @return bool True if deleted, false otherwise
     */
    public function deleteSystemLogo(): bool
    {
        $deleted = false;

        foreach (self::ALLOWED_LOGO_EXTENSIONS as $ext) {
            $path = FS_MYDOCS . self::SYSTEM_LOGO_PATH . '.' . $ext;
            if (file_exists($path)) {
                unlink($path);
                $deleted = true;
            }
        }

        $this->remove('system_logo');
        $this->save();

        return $deleted;
    }

    /**
     * Timezones list with GMT offset
     * 
     * @return array
     * @link http://stackoverflow.com/a/9328760
     */
    public function get_timezone_list()
    {
        $zones_array = [];
        $timestamp = time();
        $timezone = date_default_timezone_get();
        foreach (timezone_identifiers_list() as $key => $zone) {
            date_default_timezone_set($zone);
            $zones_array[$key]['zone'] = $zone;
            $zones_array[$key]['diff_from_GMT'] = 'UTC/GMT ' . date('P', $timestamp);
        }

        date_default_timezone_set($timezone);
        return $zones_array;
    }

    public function new_codigo_options()
    {
        return array(
            'eneboo' => 'Compatible con Eneboo',
            'new' => 'TIPO + EJERCICIO + ' . strtoupper(FS_SERIE) . ' + NÚMERO',
            '0-NUM' => 'Número continuo (con 0s)',
            'NUM' => 'Número continuo',
            'SERIE-YY-0-NUM' => strtoupper(FS_SERIE) . ' + AÑO (2 díg.) + NÚMERO (con 0s)',
            'SERIE-YY-0-NUM-CORTO' => strtoupper(FS_SERIE) . ' + AÑO (2 díg.) + NÚMERO (mín. 4 car.)'
        );
    }

    /**
     * Lista de opciones para NF0
     * @return integer[]
     */
    public function nf0()
    {
        return array(0, 1, 2, 3, 4, 5, 6);
    }

    /**
     * Lista de opciones para NF1
     * @return array
     */
    public function nf1()
    {
        return array(
            ',' => 'coma',
            '.' => 'punto',
            ' ' => '(espacio en blanco)'
        );
    }

    public function reset()
    {
        if (file_exists(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'config2.ini')) {
            return unlink(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'config2.ini');
        }

        return true;
    }

    public function save()
    {
        $file = fopen(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'config2.ini', 'w');
        if ($file) {
            foreach ($GLOBALS['config2'] as $i => $value) {
                $saveValue = is_numeric($value) ? $value : "'" . $value . "'";
                fwrite($file, $i . " = " . $saveValue . ";\n");
            }

            fclose($file);
            return true;
        }

        return false;
    }

    /**
     * Devuelve la lista de elementos a traducir
     * @return array
     */
    public function traducciones()
    {
        $clist = [];
        $include = array(
            'factura', 'facturas', 'factura_simplificada', 'factura_rectificativa',
            'albaran', 'albaranes', 'pedido', 'pedidos', 'presupuesto', 'presupuestos',
            'provincia', 'apartado', 'cifnif', 'iva', 'irpf', 'numero2', 'serie', 'series'
        );

        foreach ($GLOBALS['config2'] as $i => $value) {
            if (in_array($i, $include)) {
                $clist[] = array('nombre' => $i, 'valor' => $value);
            }
        }

        return $clist;
    }
}
