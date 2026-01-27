<?php

namespace FSFramework\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use FacturaScripts\Core\Html;

/**
 * Base controller for modern FSFramework controllers.
 * 
 * This class provides common functionality for modern controllers,
 * including rendering views, returning JSON responses, and redirecting.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class BaseController
{
    /**
     * Render a Twig template.
     * 
     * @param string $template Template name (without .html.twig extension)
     * @param array $parameters Parameters to pass to the template
     * @return Response Rendered response
     */
    protected function render(string $template, array $parameters = []): Response
    {
        $content = Html::render($template, $parameters);
        return new Response($content);
    }

    /**
     * Return a JSON response.
     * 
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return JsonResponse JSON response
     */
    protected function json($data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Redirect to another URL.
     * 
     * @param string $url Target URL
     * @param int $status HTTP status code (302 by default)
     * @return Response Redirect response
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return new Response('', $status, ['Location' => $url]);
    }

    /**
     * Generate a URL from a route name and parameters.
     * 
     * @param string $routeName Route name
     * @param array $parameters Route parameters
     * @param bool $absolute Whether to generate an absolute URL
     * @return string Generated URL
     */
    protected function generateUrl(string $routeName, array $parameters = [], bool $absolute = false): string
    {
        return \FSFramework\Core\Kernel::router()->generate($routeName, $parameters, $absolute ? \Symfony\Component\Routing\Generator\UrlGenerator::ABSOLUTE_URL : \Symfony\Component\Routing\Generator\UrlGenerator::ABSOLUTE_PATH);
    }
}