<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 * Helper para manejar subidas de archivos grandes por chunks usando Resumable.js
 * 
 * Uso:
 * $uploader = new fs_chunked_upload('/ruta/destino/', array('xlsx', 'xls'));
 * $result = $uploader->handle_chunk();
 * 
 * if ($result['complete']) {
 *     // Archivo completo, procesar $result['file_path']
 * }
 * 
 * @author FSFramework
 */
class fs_chunked_upload
{
    /**
     * Directorio destino para los archivos
     * @var string
     */
    private $upload_dir;
    
    /**
     * Directorio temporal para chunks
     * @var string
     */
    private $temp_dir;
    
    /**
     * Extensiones de archivo permitidas
     * @var array
     */
    private $allowed_extensions;
    
    /**
     * Tamaño máximo de archivo en bytes
     * @var int
     */
    private $max_file_size;
    
    /**
     * Último mensaje de error
     * @var string
     */
    private $last_error;
    
    /**
     * Constructor
     * 
     * @param string $upload_dir Directorio destino
     * @param array $allowed_extensions Extensiones permitidas
     * @param int $max_file_size_mb Tamaño máximo en MB (default: 500)
     */
    public function __construct($upload_dir, $allowed_extensions = array(), $max_file_size_mb = 500)
    {
        $this->upload_dir = rtrim($upload_dir, '/') . '/';
        $this->temp_dir = sys_get_temp_dir() . '/fs_chunks/';
        $this->allowed_extensions = array_map('strtolower', $allowed_extensions);
        $this->max_file_size = $max_file_size_mb * 1024 * 1024;
        $this->last_error = '';
        
        // Crear directorios si no existen
        $this->ensure_directory($this->upload_dir);
        $this->ensure_directory($this->temp_dir);
    }
    
    /**
     * Manejar un chunk subido
     * 
     * @param string|null $custom_filename Nombre personalizado para el archivo final
     * @return array Resultado con 'success', 'complete', 'file_path', 'message'
     */
    public function handle_chunk($custom_filename = null)
    {
        // Obtener parámetros de Resumable.js
        $resumable_identifier = $this->get_param('resumableIdentifier');
        $resumable_filename = $this->get_param('resumableFilename');
        $resumable_chunk_number = (int) $this->get_param('resumableChunkNumber');
        $resumable_total_chunks = (int) $this->get_param('resumableTotalChunks');
        $resumable_total_size = (int) $this->get_param('resumableTotalSize');
        
        // Manejar petición GET (verificar si chunk existe)
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $this->check_chunk_exists($resumable_identifier, $resumable_chunk_number);
        }
        
        // Manejar petición POST (subir chunk)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->receive_chunk(
                $resumable_identifier,
                $resumable_filename,
                $resumable_chunk_number,
                $resumable_total_chunks,
                $resumable_total_size,
                $custom_filename
            );
        }
        
        return $this->error_response('Método no soportado');
    }
    
    /**
     * Verificar si un chunk ya existe (para reanudar subidas)
     */
    private function check_chunk_exists($identifier, $chunk_number)
    {
        $chunk_file = $this->get_chunk_path($identifier, $chunk_number);
        
        if (file_exists($chunk_file)) {
            return array(
                'success' => true,
                'complete' => false,
                'message' => 'Chunk exists'
            );
        }
        
        // Retornar 404 para indicar que no existe
        http_response_code(404);
        return array(
            'success' => false,
            'complete' => false,
            'message' => 'Chunk not found'
        );
    }
    
    /**
     * Recibir y guardar un chunk
     */
    private function receive_chunk($identifier, $filename, $chunk_number, $total_chunks, $total_size, $custom_filename)
    {
        // Validar extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!empty($this->allowed_extensions) && !in_array($ext, $this->allowed_extensions)) {
            return $this->error_response('Tipo de archivo no permitido: ' . $ext);
        }
        
        // Validar tamaño total
        if ($total_size > $this->max_file_size) {
            $max_mb = round($this->max_file_size / 1024 / 1024);
            return $this->error_response("El archivo excede el tamaño máximo de {$max_mb}MB");
        }
        
        // Verificar que se recibió el archivo
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error_code = isset($_FILES['file']) ? $_FILES['file']['error'] : 'NO_FILE';
            return $this->error_response('Error al recibir el chunk: ' . $this->get_upload_error_message($error_code));
        }
        
        // Crear directorio para chunks de este archivo
        $chunk_dir = $this->temp_dir . $this->sanitize_identifier($identifier) . '/';
        $this->ensure_directory($chunk_dir);
        
        // Guardar chunk
        $chunk_file = $this->get_chunk_path($identifier, $chunk_number);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $chunk_file)) {
            return $this->error_response('Error al guardar el chunk');
        }
        
        // Verificar si todos los chunks están completos
        if ($this->all_chunks_received($identifier, $total_chunks)) {
            // Combinar chunks
            $final_filename = $custom_filename ?: $filename;
            $final_path = $this->upload_dir . $final_filename;
            
            if ($this->combine_chunks($identifier, $total_chunks, $final_path)) {
                // Limpiar chunks temporales
                $this->cleanup_chunks($identifier);
                
                return array(
                    'success' => true,
                    'complete' => true,
                    'file_path' => $final_path,
                    'filename' => $final_filename,
                    'filesize' => filesize($final_path),
                    'message' => 'Archivo subido correctamente'
                );
            } else {
                return $this->error_response('Error al combinar los chunks');
            }
        }
        
        // Chunk recibido pero archivo incompleto
        return array(
            'success' => true,
            'complete' => false,
            'chunk' => $chunk_number,
            'total' => $total_chunks,
            'message' => "Chunk {$chunk_number}/{$total_chunks} recibido"
        );
    }
    
    /**
     * Verificar si todos los chunks fueron recibidos
     */
    private function all_chunks_received($identifier, $total_chunks)
    {
        for ($i = 1; $i <= $total_chunks; $i++) {
            $chunk_file = $this->get_chunk_path($identifier, $i);
            if (!file_exists($chunk_file)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Combinar todos los chunks en un archivo final
     */
    private function combine_chunks($identifier, $total_chunks, $final_path)
    {
        $fp = fopen($final_path, 'wb');
        if (!$fp) {
            $this->last_error = 'No se pudo crear el archivo destino';
            return false;
        }
        
        for ($i = 1; $i <= $total_chunks; $i++) {
            $chunk_file = $this->get_chunk_path($identifier, $i);
            $chunk_data = file_get_contents($chunk_file);
            if ($chunk_data === false) {
                fclose($fp);
                @unlink($final_path);
                $this->last_error = "No se pudo leer el chunk {$i}";
                return false;
            }
            fwrite($fp, $chunk_data);
        }
        
        fclose($fp);
        return true;
    }
    
    /**
     * Limpiar chunks temporales
     */
    private function cleanup_chunks($identifier)
    {
        $chunk_dir = $this->temp_dir . $this->sanitize_identifier($identifier) . '/';
        
        if (is_dir($chunk_dir)) {
            $files = glob($chunk_dir . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($chunk_dir);
        }
    }
    
    /**
     * Obtener ruta de un chunk
     */
    private function get_chunk_path($identifier, $chunk_number)
    {
        $safe_id = $this->sanitize_identifier($identifier);
        return $this->temp_dir . $safe_id . '/chunk_' . str_pad($chunk_number, 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * Sanitizar identificador de archivo
     */
    private function sanitize_identifier($identifier)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);
    }
    
    /**
     * Obtener parámetro de GET o POST
     */
    private function get_param($name, $default = '')
    {
        if (isset($_GET[$name])) {
            return $_GET[$name];
        }
        if (isset($_POST[$name])) {
            return $_POST[$name];
        }
        return $default;
    }
    
    /**
     * Asegurar que un directorio existe
     */
    private function ensure_directory($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
    
    /**
     * Generar respuesta de error
     */
    private function error_response($message)
    {
        $this->last_error = $message;
        http_response_code(400);
        return array(
            'success' => false,
            'complete' => false,
            'message' => $message
        );
    }
    
    /**
     * Obtener mensaje de error de subida
     */
    private function get_upload_error_message($error_code)
    {
        $errors = array(
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el límite de PHP',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el límite del formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo',
            UPLOAD_ERR_EXTENSION => 'Extensión de PHP detuvo la subida'
        );
        
        return isset($errors[$error_code]) ? $errors[$error_code] : "Error desconocido ({$error_code})";
    }
    
    /**
     * Obtener último error
     */
    public function get_last_error()
    {
        return $this->last_error;
    }
    
    /**
     * Enviar respuesta JSON
     */
    public static function send_json_response($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
