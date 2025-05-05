<?php
/**
 * This file is part of FS-Framework
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

// Mostrar todos los errores de PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verificar si el autoloader existe
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Si no existe el autoloader, cargamos manualmente la clase
    require_once __DIR__ . '/src/Service/SystemIntegrityChecker.php';
}

// Establecer el tipo de contenido como JSON
header('Content-Type: application/json');

try {
    // Crear instancia del verificador de integridad
    $checker = new FSFramework\Service\SystemIntegrityChecker();
    
    // Ejecutar todas las comprobaciones
    $results = $checker->runAllChecks();
    
    // Devolver los resultados como JSON
    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
} catch (Exception $e) {
    // Devolver error como JSON
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
