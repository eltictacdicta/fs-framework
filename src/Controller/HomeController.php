<?php

namespace FSFramework\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador para la página de inicio
 */
class HomeController extends AbstractController
{
    /**
     * @Route("/", name="homepage")
     */
    public function index(): Response
    {
        return $this->render('@app/home.html.twig', [
            'title' => 'Bienvenido a FS-Framework',
            'message' => 'Framework modular basado en PHP con soporte para plugins',
        ]);
    }
}
