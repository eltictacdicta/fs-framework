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

use Throwable;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;

/**
 * Gestor de tokens CSRF para protección de formularios.
 * 
 * Compatible con formularios legacy de FSFramework.
 * Proporciona funciones para generar y validar tokens CSRF.
 * Incluye detección de reutilización de tokens (one-time use).
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
     * Per-request cache: evita re-chequeos del token-presence guard.
     */
    private static bool $tokenVerified = false;

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
     * TTL para el registro de tokens usados (segundos).
     * Debe ser mayor que la vida máxima de un token CSRF.
     */
    private const USED_TOKENS_TTL = 600;

    /**
     * Máximo de tokens usados almacenados a la vez.
     */
    private const MAX_USED_TOKENS = 500;

    /**
     * Clave de caché para el registro de tokens usados.
     */
    private const USED_TOKENS_CACHE_KEY = 'csrf_used_tokens';

    /**
     * Inicializa y retorna el gestor de tokens CSRF.
     * Usa almacenamiento en sesión PHP nativa.
     */
    public static function getManager(): CsrfTokenManager
    {
        if (self::$manager === null) {
            // Asegurar que la sesión está iniciada
            self::ensureSession();
            
            // Usar almacenamiento directo en $_SESSION — evita la complejidad
            // de SessionTokenStorage + PhpBridgeSessionStorage que no persiste
            // correctamente los tokens entre requests.
            $storage = new NativeSessionCsrfStorage();

            // Generador de tokens seguros
            $generator = new UriSafeTokenGenerator();
            
            self::$manager = new CsrfTokenManager($generator, $storage);

            // Token-presence guard: ensure a default token exists.
            // Uses getToken() which respects the manager's internal namespace
            // (e.g. 'https-' prefix) and only creates a token if one doesn't exist.
            if (!self::$tokenVerified) {
                self::$manager->getToken(self::DEFAULT_TOKEN_ID);
                self::$tokenVerified = true;
            }
        }
        
        return self::$manager;
    }

    /**
     * Asegura que existe una sesión PHP activa.
     * Usa PhpBridgeSessionStorage para integrarse con sesiones ya iniciadas por PHP.
     */
    private static function ensureSession(): void
    {
        if (self::$session === null) {
            if (class_exists(SessionManager::class)) {
                self::$session = SessionManager::getInstance()->getSymfonySession();
                return;
            }

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // Usar PhpBridgeSessionStorage para sesiones ya iniciadas por código legacy
            $storage = new PhpBridgeSessionStorage();
            self::$session = new Session($storage);
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
        ) . sprintf(
            '<input type="hidden" name="_token" value="%s">',
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
     * @return string|null El token si existe, null si no
     */
    public static function getTokenFromRequest(Request $request): ?string
    {
        // Primero buscar en POST
        $token = $request->request->get(self::FIELD_NAME);

        if (empty($token)) {
            $token = $request->request->get('_token');
        }
        
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
     * @param string|null $tokenId Identificador del token
     * @return bool True si el token es válido
     */
    public static function validateRequest(
        Request $request,
        ?string $tokenId = null
    ): bool {
        $token = self::getTokenFromRequest($request);

        if ($token === null) {
            return false;
        }

        return self::isValid($token, $tokenId);
    }

    /**
     * Marca un token como usado para evitar reutilización (one-time use).
     *
     * Tras validar un token con éxito, debe llamarse a este método para
     * registrarlo como consumido. Los tokens marcados como usados no
     * podrán validarse de nuevo.
     *
     * @param string $token El valor del token
     * @param string|null $tokenId Identificador del token
     */
    public static function markAsUsed(string $token, ?string $tokenId = null): void
    {
        try {
            $cache = \FSFramework\Cache\CacheManager::getInstance();
            $usedTokens = $cache->getItem(self::USED_TOKENS_CACHE_KEY, []);
            $tokenIdKey = $tokenId ?? self::DEFAULT_TOKEN_ID;
            $cacheKey = $tokenIdKey . ':' . md5($token);

            if (!is_array($usedTokens)) {
                $usedTokens = [];
            }

            // Limitar el tamaño del array para evitar consumo excesivo de memoria
            if (count($usedTokens) >= self::MAX_USED_TOKENS) {
                $usedTokens = array_slice($usedTokens, -self::MAX_USED_TOKENS + 1, null, true);
            }

            $usedTokens[$cacheKey] = time();
            $cache->set(self::USED_TOKENS_CACHE_KEY, $usedTokens, self::USED_TOKENS_TTL);
        } catch (\Throwable) {
        }
    }

    /**
     * Verifica si un token ya ha sido usado (one-time use).
     *
     * @param string $token El valor del token
     * @param string|null $tokenId Identificador del token
     * @return bool True si el token ya fue usado
     */
    public static function isReused(string $token, ?string $tokenId = null): bool
    {
        try {
            $cache = \FSFramework\Cache\CacheManager::getInstance();
            $usedTokens = $cache->getItem(self::USED_TOKENS_CACHE_KEY, []);
            $tokenIdKey = $tokenId ?? self::DEFAULT_TOKEN_ID;
            $cacheKey = $tokenIdKey . ':' . md5($token);

            return isset($usedTokens[$cacheKey]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Valida un token CSRF con protección contra reutilización.
     *
     * Si el token es válido pero ya fue usado, se considera inválido.
     * Después de una validación exitosa, el token se marca como usado.
     *
     * @param string $token El valor del token a validar
     * @param string|null $tokenId Identificador del token
     * @param bool $preventReuse Si es true, bloquea la reutilización del token
     * @return bool True si el token es válido y no ha sido reutilizado
     */
    public static function isValidWithReuseCheck(
        string $token,
        ?string $tokenId = null,
        bool $preventReuse = true
    ): bool {
        $tokenId = $tokenId ?? self::DEFAULT_TOKEN_ID;

        if (!self::isValid($token, $tokenId)) {
            return false;
        }

        if ($preventReuse && self::isReused($token, $tokenId)) {
            return false;
        }

        if ($preventReuse) {
            self::markAsUsed($token, $tokenId);
        }

        return true;
    }
}

/**
 * Almacenamiento de tokens CSRF que lee/escribe directamente en $_SESSION.
 * 
 * Evita la complejidad de SessionTokenStorage + PhpBridgeSessionStorage,
 * donde los bags de Symfony no sincronizan correctamente con $_SESSION
 * y los tokens no persisten entre requests.
 */
class NativeSessionCsrfStorage implements TokenStorageInterface
{
    private const SESSION_KEY = '_csrf';

    public function getToken(string $tokenId): string
    {
        $this->ensureSession();
        $token = (string) ($_SESSION[self::SESSION_KEY][$tokenId] ?? '');
        return $token;
    }

    public function setToken(string $tokenId, string $token): void
    {
        $this->ensureSession();
        $_SESSION[self::SESSION_KEY][$tokenId] = $token;
    }

    public function hasToken(string $tokenId): bool
    {
        $this->ensureSession();
        return isset($_SESSION[self::SESSION_KEY][$tokenId]);
    }

    public function removeToken(string $tokenId): ?string
    {
        $this->ensureSession();
        $token = $_SESSION[self::SESSION_KEY][$tokenId] ?? null;
        unset($_SESSION[self::SESSION_KEY][$tokenId]);
        return $token;
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (class_exists(SessionManager::class)) {
                session_name(SessionManager::resolveSessionName());
            }
            @session_start();
            // Garantizar guardado incluso con exit() tempranos
            register_shutdown_function(function () {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
            });
        }
    }
}
