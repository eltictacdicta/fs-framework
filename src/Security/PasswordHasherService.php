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

use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Servicio de hash de contraseñas usando Symfony PasswordHasher.
 * 
 * Reemplaza el hash SHA1 legacy por algoritmos seguros (bcrypt/argon2).
 * Compatible con contraseñas legacy mediante migración automática.
 * 
 * Uso:
 *   $hasher = new PasswordHasherService();
 *   
 *   // Crear hash para nueva contraseña
 *   $hash = $hasher->hash('mi_password');
 *   
 *   // Verificar contraseña
 *   if ($hasher->verify($hash, 'mi_password')) { ... }
 *   
 *   // Verificar con migración automática de hash legacy
 *   if ($hasher->verifyAndMigrate($storedHash, $password, $legacySalt)) {
 *       // $storedHash se actualiza al nuevo formato si era legacy
 *   }
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class PasswordHasherService
{
    private PasswordHasherInterface $hasher;
    
    /**
     * Algoritmo para nuevas contraseñas.
     * Opciones: 'bcrypt', 'argon2i', 'argon2id', 'sodium'
     */
    private string $algorithm = 'bcrypt';
    
    /**
     * Costo para bcrypt (4-31, mayor = más seguro pero más lento)
     */
    private int $cost = 13;

    public function __construct(?string $algorithm = null, ?int $cost = null)
    {
        if ($algorithm !== null) {
            $this->algorithm = $algorithm;
        }
        if ($cost !== null) {
            $this->cost = $cost;
        }

        $this->initializeHasher();
    }

    /**
     * Inicializa el hasher según el algoritmo configurado.
     */
    private function initializeHasher(): void
    {
        $factory = new PasswordHasherFactory([
            'common' => [
                'algorithm' => $this->algorithm,
                'cost' => $this->cost,
            ],
            // Hasher legacy para migración
            'legacy' => [
                'algorithm' => 'sha1',
                'encode_as_base64' => false,
                'iterations' => 1,
            ],
        ]);

        $this->hasher = $factory->getPasswordHasher('common');
    }

    /**
     * Genera un hash seguro para una contraseña.
     * 
     * @param string $plainPassword La contraseña en texto plano
     * @return string El hash de la contraseña
     */
    public function hash(string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }

    /**
     * Verifica si una contraseña coincide con un hash.
     * 
     * @param string $hashedPassword El hash almacenado
     * @param string $plainPassword La contraseña a verificar
     * @return bool True si la contraseña es correcta
     */
    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        return $this->hasher->verify($hashedPassword, $plainPassword);
    }

    /**
     * Verifica si el hash necesita ser actualizado (rehash).
     * Útil cuando se cambian los parámetros del algoritmo.
     */
    public function needsRehash(string $hashedPassword): bool
    {
        return $this->hasher->needsRehash($hashedPassword);
    }

    /**
     * Verifica una contraseña y detecta si es hash legacy (SHA1).
     * 
     * @param string $storedHash Hash almacenado en la BD
     * @param string $plainPassword Contraseña introducida
     * @param string|null $legacySalt Salt usado en el sistema legacy
     * @return bool True si la contraseña es correcta
     */
    public function verifyWithLegacySupport(
        string $storedHash,
        string $plainPassword,
        ?string $legacySalt = null
    ): bool {
        // Intentar verificar con el hasher moderno primero
        if ($this->isModernHash($storedHash)) {
            return $this->verify($storedHash, $plainPassword);
        }

        // Verificar con hash legacy (SHA1)
        return $this->verifyLegacyHash($storedHash, $plainPassword, $legacySalt);
    }

    /**
     * Verifica y migra automáticamente contraseñas legacy al nuevo formato.
     * 
     * @param string &$storedHash Hash almacenado (se actualiza si es legacy)
     * @param string $plainPassword Contraseña introducida
     * @param string|null $legacySalt Salt legacy
     * @param callable|null $saveCallback Función para guardar el nuevo hash
     * @return bool True si la contraseña es correcta
     */
    public function verifyAndMigrate(
        string &$storedHash,
        string $plainPassword,
        ?string $legacySalt = null,
        ?callable $saveCallback = null
    ): bool {
        // Si ya es hash moderno
        if ($this->isModernHash($storedHash)) {
            $valid = $this->verify($storedHash, $plainPassword);
            
            // Verificar si necesita rehash (cambio de parámetros)
            if ($valid && $this->needsRehash($storedHash)) {
                $storedHash = $this->hash($plainPassword);
                if ($saveCallback) {
                    $saveCallback($storedHash);
                }
            }
            
            return $valid;
        }

        // Verificar hash legacy
        if (!$this->verifyLegacyHash($storedHash, $plainPassword, $legacySalt)) {
            return false;
        }

        // Migrar a hash moderno
        $storedHash = $this->hash($plainPassword);
        if ($saveCallback) {
            $saveCallback($storedHash);
        }

        return true;
    }

    /**
     * Detecta si un hash es del formato moderno (bcrypt/argon2).
     */
    public function isModernHash(string $hash): bool
    {
        // bcrypt empieza con $2y$ o $2a$
        // argon2 empieza con $argon2
        return str_starts_with($hash, '$2y$') 
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$')
            || str_starts_with($hash, '$argon2');
    }

    /**
     * Verifica un hash legacy (SHA1 usado en FSFramework original).
     * 
     * El formato legacy era: sha1($salt . $password)
     */
    private function verifyLegacyHash(
        string $storedHash,
        string $plainPassword,
        ?string $legacySalt = null
    ): bool {
        // Formato legacy FSFramework: sha1(salt + password)
        if ($legacySalt !== null) {
            $legacyHash = sha1($legacySalt . $plainPassword);
            if (hash_equals($storedHash, $legacyHash)) {
                return true;
            }
        }

        // También probar sin salt (algunos sistemas legacy)
        $legacyHash = sha1($plainPassword);
        if (hash_equals($storedHash, $legacyHash)) {
            return true;
        }

        // Probar MD5 (otro formato legacy común)
        $md5Hash = md5($plainPassword);
        if (hash_equals($storedHash, $md5Hash)) {
            return true;
        }

        return false;
    }

    /**
     * Genera un salt seguro (para compatibilidad con código que lo requiera).
     * Nota: Los hashers modernos generan su propio salt internamente.
     */
    public function generateSalt(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Obtiene información sobre el hash (algoritmo usado, etc.).
     */
    public function getHashInfo(string $hash): array
    {
        $info = password_get_info($hash);
        
        if ($info['algo'] === 0) {
            // No es un hash de password_hash()
            if (strlen($hash) === 40) {
                $info['algoName'] = 'sha1 (legacy)';
            } elseif (strlen($hash) === 32) {
                $info['algoName'] = 'md5 (legacy)';
            } else {
                $info['algoName'] = 'unknown';
            }
        }

        return $info;
    }
}
