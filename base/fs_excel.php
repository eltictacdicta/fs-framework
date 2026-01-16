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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Clase helper para operaciones con archivos Excel usando PhpSpreadsheet.
 * Proporciona métodos estáticos para facilitar la creación y lectura de archivos Excel.
 * 
 * Uso básico:
 *   require_once 'base/fs_excel.php';
 *   $spreadsheet = fs_excel::create();
 *   fs_excel::write_headers($spreadsheet->getActiveSheet(), ['Col1', 'Col2']);
 *   fs_excel::download($spreadsheet, 'archivo.xlsx');
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_excel
{
    /**
     * Colores predefinidos para estilos (RGB sin #)
     */
    const COLOR_PRIMARY = '4472C4';      // Azul corporativo
    const COLOR_SUCCESS = '28A745';      // Verde
    const COLOR_WARNING = 'FFC107';      // Amarillo
    const COLOR_DANGER = 'DC3545';       // Rojo
    const COLOR_WHITE = 'FFFFFF';        // Blanco
    const COLOR_LIGHT_GRAY = 'F2F2F2';   // Gris claro para filas alternas
    
    /**
     * Flag para controlar si ya se cargó el autoloader
     * @var bool
     */
    private static $autoloader_loaded = false;
    
    /**
     * Carga el autoloader de PhpSpreadsheet si no está cargado.
     * 
     * @return void
     */
    public static function load_autoloader()
    {
        if (!self::$autoloader_loaded) {
            $autoload_path = FS_PATH . 'vendor/autoload.php';
            if (file_exists($autoload_path)) {
                require_once $autoload_path;
                self::$autoloader_loaded = true;
            } else {
                throw new Exception('PhpSpreadsheet no está instalado. Ejecuta: composer install');
            }
        }
    }
    
    /**
     * Crea una nueva instancia de Spreadsheet.
     * 
     * @param string $title Título del documento (opcional)
     * @param string $creator Autor del documento (opcional)
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public static function create($title = '', $creator = 'FSFramework')
    {
        self::load_autoloader();
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        
        // Establecer propiedades del documento
        $properties = $spreadsheet->getProperties();
        $properties->setCreator($creator);
        $properties->setLastModifiedBy($creator);
        
        if (!empty($title)) {
            $properties->setTitle($title);
        }
        
        return $spreadsheet;
    }
    
    /**
     * Lee un archivo Excel y retorna el Spreadsheet.
     * 
     * @param string $file_path Ruta al archivo Excel
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws Exception Si el archivo no existe o no se puede leer
     */
    public static function read($file_path)
    {
        self::load_autoloader();
        
        if (!file_exists($file_path)) {
            throw new Exception('El archivo no existe: ' . $file_path);
        }
        
        return \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
    }
    
    /**
     * Convierte un archivo Excel a array.
     * 
     * @param string $file_path Ruta al archivo Excel
     * @param int $sheet_index Índice de la hoja (default: 0)
     * @param bool $skip_header Si debe omitir la primera fila (default: true)
     * @return array Array bidimensional con los datos
     */
    public static function to_array($file_path, $sheet_index = 0, $skip_header = true)
    {
        $spreadsheet = self::read($file_path);
        $sheet = $spreadsheet->getSheet($sheet_index);
        $data = $sheet->toArray();
        
        if ($skip_header && !empty($data)) {
            array_shift($data);
        }
        
        // Filtrar filas vacías
        return array_filter($data, function($row) {
            return !empty(array_filter($row, function($cell) {
                return $cell !== null && $cell !== '';
            }));
        });
    }
    
    /**
     * Escribe encabezados con estilo en una hoja.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $headers Array con los nombres de las columnas
     * @param int $row Número de fila (default: 1)
     * @param string $bg_color Color de fondo RGB (default: COLOR_PRIMARY)
     * @param int $column_width Ancho de columna (default: 15)
     * @return void
     */
    public static function write_headers($sheet, $headers, $row = 1, $bg_color = null, $column_width = 15)
    {
        self::load_autoloader();
        
        if ($bg_color === null) {
            $bg_color = self::COLOR_PRIMARY;
        }
        
        $col = 'A';
        foreach ($headers as $header) {
            $cell = $col . $row;
            $sheet->setCellValue($cell, $header);
            
            // Aplicar estilo de encabezado
            $sheet->getStyle($cell)->applyFromArray(self::get_header_style($bg_color));
            
            // Ajustar ancho de columna
            $sheet->getColumnDimension($col)->setWidth($column_width);
            
            $col++;
        }
    }
    
    /**
     * Escribe una fila de datos.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $data Array con los valores de la fila
     * @param int $row Número de fila
     * @param bool $alternate_bg Si debe aplicar color de fondo alterno
     * @return void
     */
    public static function write_row($sheet, $data, $row, $alternate_bg = false)
    {
        $col = 'A';
        foreach ($data as $value) {
            $sheet->setCellValue($col . $row, $value);
            
            if ($alternate_bg && $row % 2 === 0) {
                $sheet->getStyle($col . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY],
                    ],
                ]);
            }
            
            $col++;
        }
    }
    
    /**
     * Escribe múltiples filas de datos.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $rows Array bidimensional con los datos
     * @param int $start_row Fila inicial (default: 2, asumiendo que fila 1 tiene encabezados)
     * @param bool $alternate_bg Si debe aplicar color de fondo alterno
     * @return int Número de filas escritas
     */
    public static function write_rows($sheet, $rows, $start_row = 2, $alternate_bg = false)
    {
        $row = $start_row;
        foreach ($rows as $data) {
            self::write_row($sheet, $data, $row, $alternate_bg);
            $row++;
        }
        return count($rows);
    }
    
    /**
     * Genera y descarga el archivo Excel.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param string $filename Nombre del archivo (con extensión .xlsx)
     * @return void
     */
    public static function download($spreadsheet, $filename)
    {
        self::load_autoloader();
        
        // Asegurar extensión .xlsx
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xlsx') {
            $filename .= '.xlsx';
        }
        
        // Enviar headers HTTP
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Genera y descarga como CSV.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param string $filename Nombre del archivo (con extensión .csv)
     * @param string $delimiter Delimitador (default: ;)
     * @return void
     */
    public static function download_csv($spreadsheet, $filename, $delimiter = ';')
    {
        self::load_autoloader();
        
        // Asegurar extensión .csv
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            $filename .= '.csv';
        }
        
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        // BOM para UTF-8 en Excel
        echo "\xEF\xBB\xBF";
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->setDelimiter($delimiter);
        $writer->setEnclosure('"');
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Guarda el archivo Excel en disco.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param string $file_path Ruta completa donde guardar
     * @return bool
     */
    public static function save($spreadsheet, $file_path)
    {
        self::load_autoloader();
        
        try {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($file_path);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Retorna el estilo predefinido para encabezados.
     * 
     * @param string $bg_color Color de fondo RGB
     * @return array
     */
    public static function get_header_style($bg_color = null)
    {
        self::load_autoloader();
        
        if ($bg_color === null) {
            $bg_color = self::COLOR_PRIMARY;
        }
        
        return [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => self::COLOR_WHITE],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => $bg_color],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
    }
    
    /**
     * Retorna el estilo predefinido para celdas de datos.
     * 
     * @param bool $with_border Si debe incluir bordes
     * @return array
     */
    public static function get_cell_style($with_border = true)
    {
        self::load_autoloader();
        
        $style = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ];
        
        if ($with_border) {
            $style['borders'] = [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ];
        }
        
        return $style;
    }
    
    /**
     * Aplica autoajuste de ancho a todas las columnas con datos.
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @return void
     */
    public static function auto_size_columns($sheet)
    {
        $highest_column = $sheet->getHighestColumn();
        $highest_column_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest_column);
        
        for ($col = 1; $col <= $highest_column_index; $col++) {
            $column_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($column_letter)->setAutoSize(true);
        }
    }
    
    /**
     * Crea una hoja Excel simple con encabezados y datos.
     * Método de conveniencia para exportaciones rápidas.
     * 
     * @param array $headers Array de encabezados
     * @param array $rows Array bidimensional de datos
     * @param string $sheet_title Título de la hoja
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public static function create_simple($headers, $rows, $sheet_title = 'Datos')
    {
        $spreadsheet = self::create($sheet_title);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheet_title);
        
        self::write_headers($sheet, $headers);
        self::write_rows($sheet, $rows, 2, true);
        self::auto_size_columns($sheet);
        
        return $spreadsheet;
    }
}
