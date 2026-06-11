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

declare(strict_types=1);

namespace FSFramework\Core;

use fs_file_manager;
use fs_plugin_manager;

class PluginInstaller
{
    private fs_plugin_manager $pluginManager;

    public function __construct(fs_plugin_manager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    public function installSystemUpdater(): array
    {
        return $this->installSystemUpdaterIn(FS_FOLDER);
    }

    /**
     * @return array{errors: string[], messages: string[], redirect?: string}
     */
    public function installSystemUpdaterIn(string $rootPath): array
    {
        $result = ['errors' => [], 'messages' => []];
        $pluginName = 'system_updater';
        $pluginsDir = $rootPath . '/plugins/';
        $downloadPath = $rootPath . '/download_updater.zip';
        $githubUrl = 'https://github.com/eltictacdicta/system_updater/archive/refs/heads/master.zip';

        if (file_exists($pluginsDir . $pluginName . '/controller/admin_updater.php')) {
            @unlink($downloadPath);
            if (!in_array($pluginName, $this->pluginManager->enabled()) && !$this->pluginManager->enable($pluginName)) {
                $result['errors'][] = 'No se pudo activar el plugin <b>' . $pluginName . '</b>.';
                return $result;
            }

            $result['redirect'] = 'index.php?page=admin_updater';
            return $result;
        }

        if (!is_writable($pluginsDir)) {
            $result['errors'][] = 'No hay permisos de escritura en la carpeta de plugins.';
            return $result;
        }

        if (!$this->downloadSystemUpdater($githubUrl, $downloadPath)) {
            $result['errors'][] = 'No se pudo descargar el plugin <b>' . $pluginName . '</b> desde GitHub.';
            return $result;
        }

        try {
            if (!$this->extractSystemUpdater($downloadPath, $pluginsDir)) {
                $result['errors'][] = 'No se pudo extraer el ZIP del plugin <b>' . $pluginName . '</b>.';
                return $result;
            }

            $extractedName = $this->findExtractedFolder($pluginsDir, $pluginName);
            if ($extractedName && !$this->movePluginDirectory($pluginsDir, $extractedName, $pluginName)) {
                $result['errors'][] = 'No se pudo mover el directorio del plugin <b>' . $pluginName . '</b> a su ubicación final.';
                return $result;
            }

            $this->verifyInstallation($pluginName, $pluginsDir);

            if (!file_exists($pluginsDir . $pluginName . '/controller/admin_updater.php')) {
                $result['errors'][] = 'La instalación del plugin <b>' . $pluginName . '</b> no se completó correctamente.';
                return $result;
            }

            if (!in_array($pluginName, $this->pluginManager->enabled()) && !$this->pluginManager->enable($pluginName)) {
                $result['errors'][] = 'El plugin <b>' . $pluginName . '</b> se instaló, pero no se pudo activar.';
                return $result;
            }

            $result['messages'][] = 'Plugin <b>' . $pluginName . '</b> instalado correctamente.';
            $result['redirect'] = 'index.php?page=admin_updater';
            return $result;
        } finally {
            @unlink($downloadPath);
        }
    }

    protected function downloadSystemUpdater(string $githubUrl, string $downloadPath): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        if ($ch === false) {
            return false;
        }

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
