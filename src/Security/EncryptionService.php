<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Security;

class EncryptionService
{
    private const VERSION = 'v1';

    public function isAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash');
    }

    public function encrypt(string $plainText): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Sodium no está disponible en esta instalación de PHP.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->key());

        return self::VERSION . ':' . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $cipherText): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Sodium no está disponible en esta instalación de PHP.');
        }

        $parts = explode(':', $cipherText, 2);
        if (count($parts) !== 2 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Formato de cifrado no soportado.');
        }

        $raw = base64_decode($parts[1], true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Payload cifrado inválido.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar el payload.');
        }

        return $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash(
            SecretManager::getSecret(),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
