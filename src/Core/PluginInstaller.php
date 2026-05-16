<?php

declare(strict_types=1);

namespace FSFramework\Core;

use fs_file_manager;
use fs_plugin_manager;

final class PluginInstaller
{
    private fs_plugin_manager $pluginManager;

    public function __construct(fs_plugin_manager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    public function installSystemUpdater(): void
    {
        $pluginName = 'system_updater';
        $pluginsDir = FS_FOLDER . '/plugins/';
        $downloadPath = FS_FOLDER . '/download_updater.zip';
        $githubUrl = 'https://github.com/eltictacdicta/system_updater/archive/refs/heads/master.zip';

        if (file_exists($pluginsDir . $pluginName . '/controller/admin_updater.php')) {
            if (!in_array($pluginName, $this->pluginManager->enabled())) {
                $this->pluginManager->enable($pluginName);
            }
            header('Location: index.php?page=admin_updater');
            exit;
        }

        if (!is_writable($pluginsDir)) {
            return;
        }

        if (!$this->downloadSystemUpdater($githubUrl, $downloadPath)) {
            return;
        }

        if (!$this->extractSystemUpdater($downloadPath, $pluginsDir)) {
            return;
        }

        $extractedName = $this->findExtractedFolder($pluginsDir, $pluginName);
        if ($extractedName && !$this->movePluginDirectory($pluginsDir, $extractedName, $pluginName)) {
            return;
        }

        $this->verifyInstallation($pluginName, $pluginsDir);
    }

    private function downloadSystemUpdater(string $githubUrl, string $downloadPath): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $githubUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-Updater');
        fs_curl_set_ssl($ch);

        $data = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$curlErrno && $httpCode == 200 && $data && file_put_contents($downloadPath, $data) !== false) {
            return true;
        }

        return false;
    }

    private function extractSystemUpdater(string $downloadPath, string $pluginsDir): bool
    {
        if (!fs_file_manager::extract_zip_safe($downloadPath, $pluginsDir)) {
            return false;
        }

        return true;
    }

    private function findExtractedFolder(string $pluginsDir, string $pluginName): ?string
    {
        $pattern = '/^' . preg_quote($pluginName, '/') . '(-master|-main)?$/';
        foreach (fs_file_manager::scan_folder($pluginsDir) as $f) {
            if ($f !== $pluginName && is_dir($pluginsDir . $f) && preg_match($pattern, $f)) {
                return $f;
            }
        }

        return null;
    }

    private function movePluginDirectory(string $pluginsDir, string $extractedName, string $pluginName): bool
    {
        if (file_exists($pluginsDir . $pluginName)) {
            if (!fs_file_manager::del_tree($pluginsDir . $pluginName)) {
                return false;
            }
        }

        if (@rename($pluginsDir . $extractedName, $pluginsDir . $pluginName)) {
            return true;
        }

        if ($this->recursiveCopy($pluginsDir . $extractedName, $pluginsDir . $pluginName)) {
            fs_file_manager::del_tree($pluginsDir . $extractedName);
            return true;
        }

        return false;
    }

    private function verifyInstallation(string $pluginName, string $pluginsDir): void
    {
        if (!file_exists($pluginsDir . $pluginName . '/controller/admin_updater.php')) {
            return;
        }

        if ($this->pluginManager->enable($pluginName)) {
            return;
        }
    }

    private function recursiveCopy(string $source, string $dest): bool
    {
        if (!is_dir($dest)) {
            if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
                return false;
            }
        }

        $dir = dir($source);
        if ($dir === false) {
            return false;
        }

        while (($entry = $dir->read()) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                if (!$this->recursiveCopy($srcPath, $dstPath)) {
                    $dir->close();
                    return false;
                }
            } elseif (is_file($srcPath)) {
                if (!copy($srcPath, $dstPath)) {
                    $dir->close();
                    return false;
                }
            }
        }

        $dir->close();
        return true;
    }
}
