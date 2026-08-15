<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';

final class LegacyInstallCleanupTest extends TestCase
{
    public function testLegacyCredentialsFileIsRemovedOnCleanup(): void
    {
        $legacyPath = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'initial_credentials.json';
        $dir = dirname($legacyPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($legacyPath, '{"nick":"admin","password":"secret"}');
        self::assertFileExists($legacyPath);

        self::assertTrue(\fs_user::cleanupLegacyInstallArtifacts());
        self::assertFileDoesNotExist($legacyPath);
    }

    public function testLegacyInstallAdminFileIsRemovedOnCleanup(): void
    {
        $legacyPath = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'install_admin.json';
        $dir = dirname($legacyPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($legacyPath, '{"nick":"admin"}');
        self::assertFileExists($legacyPath);

        self::assertTrue(\fs_user::cleanupLegacyInstallArtifacts());
        self::assertFileDoesNotExist($legacyPath);
    }
}
