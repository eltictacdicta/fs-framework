<?php
/**
 * Migrador automático de FS_SECRET_KEY para instalaciones antiguas.
 *
 * - Si FS_SECRET_KEY ya existe: no hace nada.
 * - Si no existe: reutiliza SecretManager para resolver o generar un secreto aleatorio.
 * - Define FS_SECRET_KEY en runtime para la petición actual.
 */
class fs_secret_migrator
{
    public static function ensure(): bool
    {
        if (defined('FS_SECRET_KEY') && !empty(FS_SECRET_KEY)) {
            return true;
        }

        if (defined('FS_SECRET_KEY')) {
            self::logMessage('FS_SECRET migration: FS_SECRET_KEY ya está definida pero vacía; el secreto válido no puede aplicarse porque las constantes no se pueden redefinir.');
            return false;
        }

        if (!class_exists(\FSFramework\Security\SecretManager::class)) {
            return false;
        }

        try {
            $secret = \FSFramework\Security\SecretManager::getSecret();
        } catch (\Throwable $exception) {
            self::logMessage('FS_SECRET migration: no se pudo resolver el secreto: ' . $exception->getMessage());
            return false;
        }

        if ($secret === '') {
            self::logMessage('FS_SECRET migration: SecretManager::getSecret() devolvió un valor vacío; no se define FS_SECRET_KEY.');
            return false;
        }

        define('FS_SECRET_KEY', $secret);

        return defined('FS_SECRET_KEY') && !empty(FS_SECRET_KEY);
    }

    private static function logMessage(string $message): void
    {
        error_log($message);
    }
}
