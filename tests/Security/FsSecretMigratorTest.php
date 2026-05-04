<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class FsSecretMigratorTest extends TestCase
{
    public function testEnsureUsesRandomSecretManagerFlowForLegacyInstall(): void
    {
        $secretManagerPath = FS_FOLDER . '/src/Security/SecretManager.php';
        $exceptionPath = FS_FOLDER . '/src/Security/Exception/MissingSecretKeyException.php';
        $migratorPath = FS_FOLDER . '/base/fs_secret_migrator.php';
        $php = escapeshellarg(PHP_BINARY);

        $tempDir = sys_get_temp_dir() . '/fsframework_secret_migrator_' . uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/config.php', "<?php\n// legacy config without FS_SECRET_KEY\n");

        $code = <<<'PHP'
putenv('FS_SECRET_KEY');
unset($_ENV['FS_SECRET_KEY'], $_SERVER['FS_SECRET_KEY']);

define('FS_FOLDER', __TEMP_DIR__);

require __EXCEPTION__;
require __SECRET_MANAGER__;
require __MIGRATOR__;

\FSFramework\Security\SecretManager::resetCache();

$result = fs_secret_migrator::ensure();
$secret = defined('FS_SECRET_KEY') ? constant('FS_SECRET_KEY') : '';
$config = file_get_contents(FS_FOLDER . '/config.php');
$secretFile = FS_FOLDER . '/.fs_secret_key';

fwrite(STDOUT, json_encode([
    'result' => $result,
    'secretLength' => strlen($secret),
    'configContainsConstant' => strpos($config, "define('FS_SECRET_KEY'") !== false,
    'secretFileExists' => file_exists($secretFile),
    'secretFileReadable' => is_readable($secretFile),
]));
PHP;

        $wrappedCode = str_replace(
            ['__TEMP_DIR__', '__EXCEPTION__', '__SECRET_MANAGER__', '__MIGRATOR__'],
            [
                var_export($tempDir, true),
                var_export($exceptionPath, true),
                var_export($secretManagerPath, true),
                var_export($migratorPath, true),
            ],
            $code
        );

        $command = $php . ' -r ' . escapeshellarg($wrappedCode);
        exec($command, $output, $exitCode);

        $secretFile = $tempDir . '/.fs_secret_key';
        if (file_exists($secretFile)) {
            chmod($secretFile, 0600);
            @unlink($secretFile);
        }
        @unlink($tempDir . '/config.php');
        @rmdir($tempDir);

        $this->assertSame(0, $exitCode, 'Child process failed: ' . implode("\n", $output));

        $payload = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['result']);
        $this->assertSame(64, $payload['secretLength']);
        $this->assertFalse($payload['configContainsConstant']);
        $this->assertTrue($payload['secretFileExists']);
        $this->assertTrue($payload['secretFileReadable']);
    }
}