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

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;

/**
 * Adaptador que envuelve fs_user para hacerlo compatible con Symfony Security.
 * 
 * Implementa UserInterface de Symfony para permitir:
 * - Uso con Symfony Security components
 * - Voters para control de acceso granular
 * - Integración con firewalls de Symfony
 * 
 * Uso:
 *   $fsUser = new fs_user();
 *   $fsUser = $fsUser->get('admin');
 *   
 *   $user = new UserAdapter($fsUser);
 *   
 *   // Ahora compatible con Symfony Security
 *   $user->getRoles();
 *   $user->getUserIdentifier();
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class UserAdapter implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    private object $legacyUser;

    /**
     * @param object $legacyUser Instancia de fs_user
     */
    public function __construct(object $legacyUser)
    {
        $this->legacyUser = $legacyUser;
    }

    /**
     * Crea un UserAdapter desde un nick de usuario.
     */
    public static function fromNick(string $nick): ?self
    {
        if (!class_exists('fs_user')) {
            return null;
        }

        $fsUser = new \fs_user();
        $user = $fsUser->get($nick);

        if (!$user) {
            return null;
        }

        return new self($user);
    }

    /**
     * Obtiene el usuario legacy envuelto.
     */
    public function getLegacyUser(): object
    {
        return $this->legacyUser;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserIdentifier(): string
    {
        return $this->legacyUser->nick ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        // Admin tiene rol de administrador
        if ($this->legacyUser->admin ?? false) {
            $roles[] = 'ROLE_ADMIN';
        }

        // Convertir accesos de fs_access a roles
        if (method_exists($this->legacyUser, 'get_accesses')) {
            $accesses = $this->legacyUser->get_accesses();
            foreach ($accesses as $access) {
                // Crear rol basado en la página
                $roles[] = 'ROLE_PAGE_' . strtoupper($access->fs_page);
                
                // Si tiene permiso de eliminación
                if ($access->allow_delete ?? false) {
                    $roles[] = 'ROLE_DELETE_' . strtoupper($access->fs_page);
                }
            }
        }

        return array_unique($roles);
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword(): ?string
    {
        return $this->legacyUser->password ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials(): void
    {
        // Limpiar datos sensibles en memoria si es necesario
    }

    /**
     * {@inheritdoc}
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->getUserIdentifier() === $user->getUserIdentifier();
    }

    /**
     * Verifica si el usuario está habilitado.
     */
    public function isEnabled(): bool
    {
        return $this->legacyUser->enabled ?? true;
    }

    /**
     * Verifica si el usuario está logueado.
     */
    public function isLoggedIn(): bool
    {
        return $this->legacyUser->logged_on ?? false;
    }

    /**
     * Verifica si el usuario es administrador.
     */
    public function isAdmin(): bool
    {
        return $this->legacyUser->admin ?? false;
    }

    /**
     * Verifica si tiene acceso a una página específica.
     */
    public function hasAccessTo(string $pageName): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (method_exists($this->legacyUser, 'have_access_to')) {
            return $this->legacyUser->have_access_to($pageName);
        }

        return false;
    }

    /**
     * Verifica si tiene permiso de eliminación en una página.
     */
    public function canDeleteIn(string $pageName): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array('ROLE_DELETE_' . strtoupper($pageName), $this->getRoles());
    }

    /**
     * Obtiene el email del usuario.
     */
    public function getEmail(): ?string
    {
        return $this->legacyUser->email ?? null;
    }

    /**
     * Obtiene el código de agente asociado.
     */
    public function getCodAgente(): ?string
    {
        return $this->legacyUser->codagente ?? null;
    }

    /**
     * Obtiene la página de inicio del usuario.
     */
    public function getHomePage(): ?string
    {
        return $this->legacyUser->fs_page ?? null;
    }

    /**
     * Obtiene la última IP de login.
     */
    public function getLastIp(): ?string
    {
        return $this->legacyUser->last_ip ?? null;
    }

    /**
     * Obtiene la fecha del último login.
     */
    public function getLastLogin(): ?string
    {
        if (method_exists($this->legacyUser, 'show_last_login')) {
            return $this->legacyUser->show_last_login();
        }
        return null;
    }

    /**
     * Acceso directo a propiedades del usuario legacy.
     */
    public function __get(string $name): mixed
    {
        return $this->legacyUser->$name ?? null;
    }

    /**
     * Verificar existencia de propiedades.
     */
    public function __isset(string $name): bool
    {
        return isset($this->legacyUser->$name);
    }

    /**
     * Llamar métodos del usuario legacy.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this->legacyUser, $name)) {
            return $this->legacyUser->$name(...$arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist on legacy user.");
    }
}
