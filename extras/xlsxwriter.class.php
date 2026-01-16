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
 * Capa de compatibilidad XLSXWriter
 * 
 * Esta clase emula la API de mk-j/php_xlsxwriter usando PhpSpreadsheet internamente.
 * Permite que código legado que usaba XLSXWriter siga funcionando sin modificaciones.
 * 
 * Uso:
 *   require_once 'extras/xlsxwriter.class.php';
 *   $writer = new XLSXWriter();
 *   $writer->writeSheetHeader('Hoja1', array('Col1' => 'string', 'Col2' => 'integer'));
 *   $writer->writeSheetRow('Hoja1', array('Valor1', 123));
 *   $writer->writeToFile('archivo.xlsx');
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class XLSXWriter
{
    // Constantes de límites de Excel
    const EXCEL_2007_MAX_ROW = 1048576;
    const EXCEL_2007_MAX_COL = 16384;
    
    /**
     * @var \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    protected $spreadsheet;
    
    /**
     * Propiedades del documento
     */
    protected $title = '';
    protected $subject = '';
    protected $author = '';
    protected $company = '';
    protected $description = '';
    protected $keywords = array();
    
    /**
     * Información de hojas
     * @var array [sheet_name => ['index' => int, 'current_row' => int, 'headers' => array]]
     */
    protected $sheets = array();
    
    /**
     * Índice de la hoja actual
     * @var int
     */
    protected $current_sheet_index = 0;
    
    /**
     * Flag para saber si PhpSpreadsheet está cargado
     * @var bool
     */
    private static $autoloader_loaded = false;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadAutoloader();
        $this->spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        // Eliminar la hoja por defecto, las crearemos según se necesiten
        $this->spreadsheet->removeSheetByIndex(0);
    }
    
    /**
     * Carga el autoloader de PhpSpreadsheet
     */
    private function loadAutoloader()
    {
        if (!self::$autoloader_loaded) {
            // Intentar cargar desde la raíz del framework
            $paths = array(
                dirname(__DIR__) . '/vendor/autoload.php',  // Desde extras/
                FS_PATH . 'vendor/autoload.php',             // Usando constante FS_PATH
            );
            
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    self::$autoloader_loaded = true;
                    return;
                }
            }
            
            self::log("Error: No se pudo cargar PhpSpreadsheet. Ejecuta: composer install");
        }
    }
    
    /**
     * Log de errores/advertencias (compatible con XLSXWriter original)
     * @param string $message
     */
    public static function log($message)
    {
        error_log("XLSXWriter: " . $message);
    }
    
    // =====================================================
    // MÉTODOS DE PROPIEDADES DEL DOCUMENTO
    // =====================================================
    
    public function setTitle($title = '')
    {
        $this->title = $title;
        $this->spreadsheet->getProperties()->setTitle($title);
    }
    
    public function setSubject($subject = '')
    {
        $this->subject = $subject;
        $this->spreadsheet->getProperties()->setSubject($subject);
    }
    
    public function setAuthor($author = '')
    {
        $this->author = $author;
        $this->spreadsheet->getProperties()->setCreator($author);
        $this->spreadsheet->getProperties()->setLastModifiedBy($author);
    }
    
    public function setCompany($company = '')
    {
        $this->company = $company;
        $this->spreadsheet->getProperties()->setCompany($company);
    }
    
    public function setKeywords($keywords = '')
    {
        $this->keywords = is_array($keywords) ? $keywords : array($keywords);
        $this->spreadsheet->getProperties()->setKeywords(
            is_array($keywords) ? implode(', ', $keywords) : $keywords
        );
    }
    
    public function setDescription($description = '')
    {
        $this->description = $description;
        $this->spreadsheet->getProperties()->setDescription($description);
    }
    
    public function setTempDir($tempdir = '')
    {
        // PhpSpreadsheet maneja esto internamente, pero aceptamos el método por compatibilidad
    }
    
    public function setRightToLeft($isRightToLeft = false)
    {
        if ($this->spreadsheet->getSheetCount() > 0) {
            $this->spreadsheet->getActiveSheet()->setRightToLeft($isRightToLeft);
        }
    }
    
    // =====================================================
    // MÉTODOS DE ESCRITURA DE HOJAS
    // =====================================================
    
    /**
     * Escribe los encabezados de una hoja
     * 
     * @param string $sheet_name Nombre de la hoja
     * @param array $header_types Array asociativo ['nombre_columna' => 'tipo'] o array simple de nombres
     * @param array $col_options Opciones de columna (ancho, etc.) - parcialmente soportado
     * @return void
     */
    public function writeSheetHeader($sheet_name, $header_types, $col_options = null)
    {
        // Crear o obtener la hoja
        $sheet = $this->getOrCreateSheet($sheet_name);
        
        // Escribir encabezados
        $col = 'A';
        $headers = array();
        
        foreach ($header_types as $header => $type) {
            // Si es array asociativo, $header es el nombre de la columna
            // Si es array simple, $header es el índice y $type es el nombre
            $header_name = is_numeric($header) ? $type : $header;
            $headers[] = $header_name;
            
            $sheet->setCellValue($col . '1', $header_name);
            
            // Aplicar estilo de encabezado (negrita)
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            
            // Aplicar ancho de columna si se especifica
            if ($col_options && isset($col_options[$header]) && isset($col_options[$header]['width'])) {
                $sheet->getColumnDimension($col)->setWidth($col_options[$header]['width']);
            } else {
                // Ancho automático basado en el contenido del header
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            $col++;
        }
        
        // Guardar información de la hoja
        $this->sheets[$sheet_name]['headers'] = $headers;
        $this->sheets[$sheet_name]['header_types'] = $header_types;
        $this->sheets[$sheet_name]['current_row'] = 2; // Los datos empiezan en la fila 2
    }
    
    /**
     * Escribe una fila de datos en una hoja
     * 
     * @param string $sheet_name Nombre de la hoja
     * @param array $row Array de valores
     * @param array $row_options Opciones de fila (estilos) - parcialmente soportado
     * @return void
     */
    public function writeSheetRow($sheet_name, $row, $row_options = null)
    {
        // Obtener o crear la hoja
        $sheet = $this->getOrCreateSheet($sheet_name);
        
        // Obtener la fila actual
        $current_row = isset($this->sheets[$sheet_name]['current_row']) 
            ? $this->sheets[$sheet_name]['current_row'] 
            : 1;
        
        // Escribir los valores
        $col = 'A';
        foreach ($row as $value) {
            $cell = $col . $current_row;
            
            // Detectar el tipo de valor y establecerlo correctamente
            if (is_numeric($value) && !is_string($value)) {
                $sheet->setCellValueExplicit(
                    $cell, 
                    $value, 
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );
            } elseif ($value instanceof \DateTime) {
                $sheet->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value));
                $sheet->getStyle($cell)->getNumberFormat()
                    ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_DDMMYYYY);
            } else {
                $sheet->setCellValue($cell, $value);
            }
            
            $col++;
        }
        
        // Aplicar estilos de fila si se especifican
        if ($row_options && is_array($row_options)) {
            $this->applyRowStyle($sheet, $current_row, count($row), $row_options);
        }
        
        // Incrementar contador de filas
        $this->sheets[$sheet_name]['current_row'] = $current_row + 1;
    }
    
    /**
     * Escribe múltiples filas a la vez
     * 
     * @param string $sheet_name Nombre de la hoja
     * @param array $rows Array de filas
     * @return void
     */
    public function writeSheet(array $rows, $sheet_name = 'Sheet1', array $header_types = array())
    {
        if (!empty($header_types)) {
            $this->writeSheetHeader($sheet_name, $header_types);
        }
        
        foreach ($rows as $row) {
            $this->writeSheetRow($sheet_name, $row);
        }
    }
    
    // =====================================================
    // MÉTODOS DE SALIDA
    // =====================================================
    
    /**
     * Guarda el archivo Excel en disco
     * 
     * @param string $filename Ruta del archivo
     * @return bool
     */
    public function writeToFile($filename)
    {
        try {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->spreadsheet);
            $writer->save($filename);
            return true;
        } catch (\Exception $e) {
            self::log("Error al guardar archivo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Devuelve el contenido del archivo Excel como string
     * 
     * @return string
     */
    public function writeToString()
    {
        ob_start();
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->spreadsheet);
        $writer->save('php://output');
        return ob_get_clean();
    }
    
    /**
     * Envía el archivo Excel directamente al navegador para descarga
     * 
     * @param string $filename Nombre del archivo para el navegador
     * @return void
     */
    public function writeToStdOut($filename = 'export.xlsx')
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->spreadsheet);
        $writer->save('php://output');
    }
    
    // =====================================================
    // MÉTODOS AUXILIARES
    // =====================================================
    
    /**
     * Obtiene o crea una hoja por nombre
     * 
     * @param string $sheet_name
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    protected function getOrCreateSheet($sheet_name)
    {
        if (!isset($this->sheets[$sheet_name])) {
            // Crear nueva hoja
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($this->spreadsheet, $sheet_name);
            $this->spreadsheet->addSheet($sheet, $this->current_sheet_index);
            
            $this->sheets[$sheet_name] = array(
                'index' => $this->current_sheet_index,
                'current_row' => 1,
                'headers' => array(),
                'header_types' => array()
            );
            
            $this->current_sheet_index++;
            return $sheet;
        }
        
        return $this->spreadsheet->getSheetByName($sheet_name);
    }
    
    /**
     * Aplica estilos a una fila
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int $row
     * @param int $colCount
     * @param array $options
     */
    protected function applyRowStyle($sheet, $row, $colCount, $options)
    {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
        $range = 'A' . $row . ':' . $lastCol . $row;
        
        $styleArray = array();
        
        // Convertir opciones de XLSXWriter a PhpSpreadsheet
        if (isset($options['font-style']) && strpos($options['font-style'], 'bold') !== false) {
            $styleArray['font']['bold'] = true;
        }
        
        if (isset($options['font-style']) && strpos($options['font-style'], 'italic') !== false) {
            $styleArray['font']['italic'] = true;
        }
        
        if (isset($options['color'])) {
            $color = ltrim($options['color'], '#');
            $styleArray['font']['color'] = array('rgb' => $color);
        }
        
        if (isset($options['fill'])) {
            $fill = ltrim($options['fill'], '#');
            $styleArray['fill'] = array(
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => array('rgb' => $fill)
            );
        }
        
        if (isset($options['halign'])) {
            $alignMap = array(
                'left' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'center' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'right' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            );
            if (isset($alignMap[$options['halign']])) {
                $styleArray['alignment']['horizontal'] = $alignMap[$options['halign']];
            }
        }
        
        if (!empty($styleArray)) {
            $sheet->getStyle($range)->applyFromArray($styleArray);
        }
    }
    
    /**
     * Convierte coordenadas de fila/columna a referencia de celda Excel
     * Método estático para compatibilidad con código que usaba XLSXWriter::xlsCell()
     * 
     * @param int $row Número de fila (0-indexed)
     * @param int $col Número de columna (0-indexed)
     * @return string Referencia de celda (ej: "A1", "B2")
     */
    public static function xlsCell($row, $col)
    {
        $col_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
        return $col_letter . ($row + 1);
    }
    
    /**
     * Obtiene el objeto Spreadsheet interno para operaciones avanzadas
     * 
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function getSpreadsheet()
    {
        return $this->spreadsheet;
    }
    
    /**
     * Destructor - libera memoria
     */
    public function __destruct()
    {
        if ($this->spreadsheet) {
            $this->spreadsheet->disconnectWorksheets();
            unset($this->spreadsheet);
        }
    }
}
