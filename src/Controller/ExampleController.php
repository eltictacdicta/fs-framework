<?php

namespace FSFramework\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de ejemplo
 */
class ExampleController extends AbstractController
{
    #[Route('/example-test', name: 'example_test')]
    public function index(): Response
    {
        return $this->render('base.html.twig', [
            'title' => 'Ejemplo de Controlador',
            'message' => 'Este es un ejemplo de controlador que utiliza Twig como sistema de plantillas',
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
