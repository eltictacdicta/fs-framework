<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {

    // Ruta de prueba global para el CMS
    // Nota: Usar una clase controladora en lugar de Closure permite el cacheo de rutas
    $routes->add('cms_test', '/cms-test')
        ->controller('FSFramework\Controller\CmsTestController::index')
        ->methods(['GET']);

    // Podemos importar las rutas de la API aquí también para centralizar, 
    // o dejarlas separadas en sus respectivos plugins.
};
