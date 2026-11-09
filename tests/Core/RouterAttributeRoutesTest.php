<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Attribute\FSRoute;
use FSFramework\Core\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;

#[FSRoute('/demo', name: 'demo_', requirements: ['id' => '\d+'], methods: ['GET'])]
final class RouterAttributeRoutesFixture
{
    #[FSRoute('/{id}', name: 'show')]
    public function show(): void
    {
    }

    #[FSRoute('/{id}/save', name: 'save', methods: ['POST'], defaults: ['format' => 'json'])]
    public function save(): void
    {
    }
}

/**
 * Lectura de atributos #[FSRoute] sin getters obsoletos de symfony/routing 7.4:
 * con display_errors activo, sus avisos se imprimían antes de las cabeceras y la
 * respuesta salía como 200 text/html al reconstruir la caché de rutas.
 */
final class RouterAttributeRoutesTest extends TestCase
{
    public function testBuildsRoutesFromAttributesWithoutDeprecations(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $no, string $msg) use (&$deprecations): bool {
            $deprecations[] = $msg;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $router = (new \ReflectionClass(Router::class))->newInstanceWithoutConstructor();
            $collection = new RouteCollection();
            (new \ReflectionMethod(Router::class, 'loadAttributeRoutesFromClass'))
                ->invoke($router, $collection, RouterAttributeRoutesFixture::class);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);

        $show = $collection->get('demo_show');
        $this->assertNotNull($show);
        $this->assertSame('/demo/{id}', $show->getPath());
        $this->assertSame(['GET'], $show->getMethods());
        $this->assertSame('\d+', $show->getRequirement('id'));
        $this->assertSame(RouterAttributeRoutesFixture::class . '::show', $show->getDefault('_controller'));

        $save = $collection->get('demo_save');
        $this->assertNotNull($save);
        $this->assertSame('/demo/{id}/save', $save->getPath());
        $this->assertSame(['POST'], $save->getMethods());
        $this->assertSame('json', $save->getDefault('format'));
    }
}
