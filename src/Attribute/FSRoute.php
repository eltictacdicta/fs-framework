<?php

namespace FSFramework\Attribute;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Attribute for defining routes in controllers.
 * 
 * Use this attribute to define routes for your controllers in FSFramework.
 * It works similar to Symfony's Route attribute but with FSFramework integration.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class FSRoute extends Route
{
    // No se necesita código adicional - hereda toda la funcionalidad de Symfony Route
}
