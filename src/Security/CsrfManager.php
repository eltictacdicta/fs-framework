<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Security;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Gestor de tokens CSRF para protección de formularios.
 * 
 * Compatible con formularios legacy de FSFramework.
 * Proporciona funciones para generar y validar tokens CSRF.
 * 
 * Uso en plantillas Twig/RainTPL:
 *   {{ csrf_field() }}
 *   {function="csrf_field()"}
 * 
 * Uso en controladores:
 *   if (!CsrfManager::isValid($token)) { ... }
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class CsrfManager
{
    private static ?CsrfTokenManager $manager = null;
    private static ?Session $session = null;
    
    /**
     * ID por defecto para tokens de formularios
     */
    public const DEFAULT_TOKEN_ID = 'fs_form';
    
    /**
     * Nombre del campo hidden en formularios
     */
    public const FIELD_NAME = '_csrf_token';
    
    /**
     * Nombre del header HTTP para peticiones AJAX
     */
    public const HEADER_NAME = 'X-CSRF-TOKEN';

    /**
     * Inicializa y retorna el gestor de tokens CSRF.
     * Usa almacenamiento en sesión PHP nativa.
     */
    public static function getManager(): CsrfTokenManager
    {
        if (self::$manager === null) {
            // Asegurar que la sesión está iniciada
            self::ensureSession();
            
            // Crear RequestStack para el storage
            $requestStack = new RequestStack();
            
            // Usar almacenamiento en sesión
            $storage = new SessionTokenStorage(self::$session);
            
            // Generador de tokens seguros
            $generator = new UriSafeTokenGenerator();
            
            self::$manager = new CsrfTokenManager($generator, $storage);
        }
        
        return self::$manager;
    }

    /**
     * Asegura que existe una sesión PHP activa.
     */
    private static function ensureSession(): void
    {
        if (self::$session === null) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            self::$session = new Session();
        }
    }

    /**
     * Genera un token CSRF para un identificador dado.
     * 
     * @param string|null $tokenId Identificador del token (usa DEFAULT_TOKEN_ID si es null)
     * @return string El valor del token
     */
    public static function generateToken(?string $tokenId = null): string
    {
        return self::getManager()
            ->getToken($tokenId ?? self::DEFAULT_TOKEN_ID)
            ->getValue();
    }

    /**
     * Valida un token CSRF.
     * 
     * @param string $token El valor del token a validar
     * @param string|null $tokenId Identificador del token (usa DEFAULT_TOKEN_ID si es null)
     * @return bool True si el token es válido
     */
    public static function isValid(string $token, ?string $tokenId = null): bool
    {
        $csrfToken = new CsrfToken($tokenId ?? self::DEFAULT_TOKEN_ID, $token);
        return self::getManager()->isTokenValid($csrfToken);
    }

    /**
     * Refresca un token CSRF (genera uno nuevo invalidando el anterior).
     * 
     * @param string|null $tokenId Identificador del token
     * @return string El nuevo valor del token
     */
    public static function refreshToken(?string $tokenId = null): string
    {
        return self::getManager()
            ->refreshToken($tokenId ?? self::DEFAULT_TOKEN_ID)
            ->getValue();
    }

    /**
     * Elimina un token CSRF.
     * 
     * @param string|null $tokenId Identificador del token
     * @return string|null El valor del token eliminado, o null si no existía
     */
    public static function removeToken(?string $tokenId = null): ?string
    {
        return self::getManager()->removeToken($tokenId ?? self::DEFAULT_TOKEN_ID);
    }

    /**
     * Genera el HTML de un campo hidden con el token CSRF.
     * Para usar directamente en plantillas.
     * 
     * @param string|null $tokenId Identificador del token
     * @return string HTML del campo input hidden
     */
    public static function field(?string $tokenId = null): string
    {
        $token = self::generateToken($tokenId);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            $escapedToken
        );
    }

    /**
     * Genera un meta tag con el token CSRF para uso con JavaScript/AJAX.
     * 
     * @param string|null $tokenId Identificador del token
     * @return string HTML del meta tag
     */
    public static function metaTag(?string $tokenId = null): string
    {
        $token = self::generateToken($tokenId);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            $escapedToken
        );
    }

    /**
     * Obtiene el token de una petición HTTP (de POST o header).
     * 
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return string|null El token si existe, null si no
     */
    public static function getTokenFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        // Primero buscar en POST
        $token = $request->request->get(self::FIELD_NAME);
        
        // Si no está en POST, buscar en header (para AJAX)
        if (empty($token)) {
            $token = $request->headers->get(self::HEADER_NAME);
        }
        
        return $token ?: null;
    }

    /**
     * Valida el token CSRF de una petición HTTP.
     * Modo conveniente que extrae y valida el token automáticamente.
     * 
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param string|null $tokenId Identificador del token
     * @return bool True si el token es válido
     */
    public static function validateRequest(
        \Symfony\Component\HttpFoundation\Request $request,
        ?string $tokenId = null
    ): bool {
        $token = self::getTokenFromRequest($request);
        
        if ($token === null) {
            return false;
        }
        
        return self::isValid($token, $tokenId);
    }
}
