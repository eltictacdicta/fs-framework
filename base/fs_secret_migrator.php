<?php
/**
 * Migrador automático de FS_SECRET_KEY para instalaciones antiguas.
 *
 * - Si FS_SECRET_KEY ya existe: no hace nada.
 * - Si no existe: deriva una clave estable (igual al fallback actual) y la escribe en config.php.
 * - Define FS_SECRET_KEY en runtime para la petición actual.
 */
class fs_secret_migrator
{
    public static function ensure(): bool
    {
        if (defined('FS_SECRET_KEY') && !empty(FS_SECRET_KEY)) {
            return true;
        }

        $secret = self::deriveFallbackSecret();
        $written = self::writeToConfig($secret);

        if (!defined('FS_SECRET_KEY')) {
            define('FS_SECRET_KEY', $secret);
        }

        return $written || (defined('FS_SECRET_KEY') && !empty(FS_SECRET_KEY));
    }

    private static function deriveFallbackSecret(): string
    {
        $parts = [];
        foreach (['FS_DB_NAME', 'FS_DB_USER', 'FS_CACHE_PREFIX', 'FS_TMP_NAME', 'FS_FOLDER'] as $const) {
            if (defined($const)) {
                $parts[] = (string) constant($const);
            }
        }

        if (empty($parts)) {
            $parts[] = __FILE__;
            $parts[] = PHP_VERSION;
        }

        return hash('sha256', implode('|', $parts));
    }

    private static function writeToConfig(string $secret): bool
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__);
        $configPath = $root . '/config.php';

        if (!file_exists($configPath) || !is_readable($configPath)) {
            return false;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        if (strpos($content, "define('FS_SECRET_KEY'") !== false) {
            return true;
        }

        if (!is_writable($configPath)) {
            error_log('FS_SECRET migration: config.php no es escribible.');
            return false;
        }

        $backupPath = $configPath . '.bak-secret-migration';
        if (!file_exists($backupPath)) {
            @copy($configPath, $backupPath);
        }

        $append = "\n// Secret key migrada automáticamente para compatibilidad de seguridad\n"
            . "define('FS_SECRET_KEY', '" . addslashes($secret) . "');\n";

        return false !== file_put_contents($configPath, rtrim($content) . "\n" . $append, LOCK_EX);
    }
}
