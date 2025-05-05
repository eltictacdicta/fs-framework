<?php
/**
 * Controlador de ejemplo para el plugin example_twig
 */

namespace FSFramework\Plugin\ExampleTwig\Controller;

use FSFramework\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de ejemplo usando Twig
 */
class ExampleController extends BaseController
{
    #[Route('/example', name: 'example_index')]
    public function index(): Response
    {
        return $this->render('base.html.twig', [
            'title' => 'Ejemplo de Plugin con Twig',
            'message' => 'Este es un ejemplo de plugin que utiliza Twig como sistema de plantillas',
            'items' => [
                'Item 1',
                'Item 2',
                'Item 3',
                'Item 4',
                'Item 5',
            ],
        ]);
    }
}
