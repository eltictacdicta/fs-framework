<?php

namespace FSFramework\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para manejar las solicitudes del sistema legacy
 */
class LegacyController extends AbstractController
{
    /**
     * Maneja las solicitudes y las redirige al sistema legacy
     */
    public function handleRequest(Request $request, string $page = ''): Response
    {
        // Guardamos la página solicitada
        if (!empty($page)) {
            $_GET['page'] = $page;
        }

        // Iniciamos el buffer de salida
        ob_start();
        
        // Incluimos el archivo index.php original
        $legacyDir = $this->getParameter('kernel.project_dir');
        include $legacyDir . '/index_legacy.php';
        
        // Obtenemos el contenido del buffer
        $content = ob_get_clean();
        
        // Devolvemos la respuesta
        return new Response($content);
    }
}
