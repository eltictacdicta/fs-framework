<?php

namespace FSFramework\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Base controller class that doesn't depend on Symfony's AbstractController
 */
class BaseController
{
    /**
     * Renders a template
     */
    protected function render(string $template, array $parameters = []): Response
    {
        // Create a simple Twig environment
        $loader = new \Twig\Loader\FilesystemLoader([
            __DIR__ . '/../../templates',
            __DIR__ . '/../../src/Template',
            __DIR__ . '/../../plugins/example_twig/Template'
        ]);
        
        $twig = new \Twig\Environment($loader, [
            'debug' => true,
            'cache' => false
        ]);
        
        // Render the template
        $content = $twig->render($template, $parameters);
        
        // Create and return a Response object
        return new Response($content);
    }
}
