<?php

declare(strict_types=1);

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

use FSFramework\Security\Exception\MissingSecretKeyException;

class SecretManager
{
    private static ?string $secret = null;

    private const SECRET_FILE = '.fs_secret_key';

    public static function getSecret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $configuredSecret = self::resolveConfiguredSecret();
        if ($configuredSecret === null) {
            $configuredSecret = self::autoGenerateSecret();
        }

        if ($configuredSecret === null) {
            throw new MissingSecretKeyException(
                'FS_SECRET_KEY must be defined in config.php or as environment variable. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        self::$secret = $configuredSecret;
        return self::$secret;
    }

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::getSecret());
    }

    private static function resolveConfiguredSecret(): ?string
    {
        $constSecret = self::getConstantSecret();
        if ($constSecret !== null) {
            return $constSecret;
        }

        $envSecret = getenv('FS_SECRET_KEY');
        if ($envSecret !== false && $envSecret !== '') {
            return $envSecret;
        }

        $fileSecret = self::readSecretFromFile();
        if ($fileSecret !== null) {
            return $fileSecret;
        }

        return null;
    }

    private static function getConstantSecret(): ?string
    {
        if (!defined('FS_SECRET_KEY')) {
            return null;
        }

        $value = constant('FS_SECRET_KEY');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function getSecretFilePath(): string
    {
        $baseDir = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        return $baseDir . DIRECTORY_SEPARATOR . self::SECRET_FILE;
    }

    private static function readSecretFromFile(): ?string
    {
        $path = self::getSecretFilePath();
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $secret = null;

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            if (str_starts_with($candidate, '#') || str_starts_with($candidate, '//') || str_starts_with($candidate, ';')) {
                continue;
            }

            $secret = $candidate;
        }

        if ($secret === null || strlen($secret) < 32) {
            return null;
        }

        return $secret;
    }

    private static function autoGenerateSecret(): ?string
    {
        $path = self::getSecretFilePath();
        $dir = dirname($path);

        if (!is_writable($dir)) {
            error_log('FSFramework SecretManager: Cannot auto-generate secret key - directory not writable: ' . $dir);
            return null;
        }

        try {
            $secret = bin2hex(random_bytes(32));

            $header = "# FSFramework Secret Key - Generated automatically\n";
            $header .= "# DO NOT share this file or commit it to version control\n";
            $header .= "# Generated: " . date('Y-m-d H:i:s') . "\n";

            if (file_put_contents($path, $header . $secret, LOCK_EX) === false) {
                error_log('FSFramework SecretManager: Failed to write secret key file: ' . $path);
                return null;
            }

            if (!chmod($path, 0600)) {
                if (PHP_OS_FAMILY === 'Windows') {
                    error_log('FSFramework SecretManager: Warning - chmod(0600) is not enforced on Windows, removing generated secret key file: ' . $path);
                } else {
                    error_log('FSFramework SecretManager: Failed to secure secret key file permissions with chmod 0600: ' . $path);
                }

                if (!self::cleanupGeneratedSecretFile($path, 'chmod(0600) failure')) {
                    return null;
                }

                return null;
            }

            error_log('FSFramework SecretManager: Auto-generated secret key saved to ' . $path .
                      ' - Consider moving this value to config.php as FS_SECRET_KEY for better security.');

            return $secret;
        } catch (\Exception $e) {
            error_log('FSFramework SecretManager: Exception generating secret: ' . $e->getMessage());
            return null;
        }
    }

    public static function resetCache(): void
    {
        self::$secret = null;
    }

    private static function cleanupGeneratedSecretFile(string $path, string $reason): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (unlink($path)) {
            return true;
        }

        if (self::wipeSecretFileContents($path)) {
            error_log('FSFramework SecretManager: Could not unlink generated secret key file after ' . $reason . ', but file contents were wiped: ' . $path);
            return true;
        }

        error_log('FSFramework SecretManager: Could not unlink or wipe generated secret key file after ' . $reason . ': ' . $path);
        return false;
    }

    private static function wipeSecretFileContents(string $path): bool
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return false;
        }

        $wiped = ftruncate($handle, 0);
        if ($wiped) {
            $wiped = fflush($handle);
        }

        fclose($handle);
        return $wiped;
    }
}
