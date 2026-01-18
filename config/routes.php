<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

return function (RoutingConfigurator $routes): void {

    // Ruta de prueba global para el CMS
    $routes->add('cms_test', '/cms-test')
        ->controller(function (Request $request) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Global Symfony Routing is working!',
                'type' => 'CMS Core'
            ]);
        })
        ->methods(['GET']);

    // Podemos importar las rutas de la API aquí también para centralizar, 
    // o dejarlas separadas en sus respectivos plugins.
};
