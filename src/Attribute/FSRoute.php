<?php

namespace FSFramework\Attribute;

use Attribute;

/**
 * Atributo para registro declarativo de rutas en controladores.
 * 
 * Uso:
 * #[FSRoute('/admin/users', methods: ['GET'], name: 'admin_users')]
 * class admin_users extends fs_controller { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FSRoute
{
    /**
     * @param string $path Ruta URL (ej: '/admin/users' o '/api/v1/users/{id}')
     * @param array $methods Métodos HTTP permitidos (GET, POST, PUT, DELETE, etc.)
     * @param string|null $name Nombre único de la ruta para generación de URLs
     * @param array $defaults Valores por defecto para parámetros de ruta
     * @param array $requirements Requisitos regex para parámetros (ej: ['id' => '\d+'])
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $defaults = [],
        public array $requirements = []
    ) {
    }
}
