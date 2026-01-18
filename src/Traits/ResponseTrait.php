<?php

namespace FSFramework\Traits;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trait con helpers para respuestas HTTP modernas.
 * 
 * Puede ser usado en controladores que hereden de fs_controller
 * para retornar respuestas Symfony HttpFoundation.
 */
trait ResponseTrait
{
    /**
     * Retorna una respuesta JSON.
     *
     * @param mixed $data Datos a serializar como JSON
     * @param int $status Código de estado HTTP
     * @param array $headers Headers adicionales
     * @return JsonResponse
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Retorna una respuesta de redirección.
     *
     * @param string $url URL de destino
     * @param int $status Código de estado (302 temporal, 301 permanente)
     * @return RedirectResponse
     */
    protected function redirect(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Redirecciona a una página del sistema.
     *
     * @param string $pageName Nombre de la página (ej: 'admin_home')
     * @param array $params Parámetros adicionales para la URL
     * @return RedirectResponse
     */
    protected function redirectToPage(string $pageName, array $params = []): RedirectResponse
    {
        $url = 'index.php?page=' . urlencode($pageName);
        foreach ($params as $key => $value) {
            $url .= '&' . urlencode($key) . '=' . urlencode($value);
        }
        return new RedirectResponse($url);
    }

    /**
     * Retorna una respuesta HTML.
     *
     * @param string $content Contenido HTML
     * @param int $status Código de estado HTTP
     * @param array $headers Headers adicionales
     * @return Response
     */
    protected function html(string $content, int $status = 200, array $headers = []): Response
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';
        return new Response($content, $status, $headers);
    }

    /**
     * Retorna una respuesta 404 Not Found.
     *
     * @param string $message Mensaje opcional
     * @return Response
     */
    protected function notFound(string $message = 'Not Found'): Response
    {
        return new Response($message, 404);
    }

    /**
     * Retorna una respuesta 403 Forbidden.
     *
     * @param string $message Mensaje opcional
     * @return Response
     */
    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return new Response($message, 403);
    }

    /**
     * Retorna una respuesta 400 Bad Request.
     *
     * @param string $message Mensaje opcional
     * @return Response
     */
    protected function badRequest(string $message = 'Bad Request'): Response
    {
        return new Response($message, 400);
    }

    /**
     * Retorna una respuesta vacía con código de estado personalizado.
     *
     * @param int $status Código de estado HTTP
     * @return Response
     */
    protected function noContent(int $status = 204): Response
    {
        return new Response('', $status);
    }

    /**
     * Retorna una respuesta de archivo para descarga.
     *
     * @param string $content Contenido del archivo
     * @param string $filename Nombre del archivo para descarga
     * @param string $contentType Tipo MIME del archivo
     * @return Response
     */
    protected function file(string $content, string $filename, string $contentType = 'application/octet-stream'): Response
    {
        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content)
        ]);
    }
}
