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
use ZipArchive;

final class PluginActionHandler
{
    private fs_plugin_manager $pluginManager;

    public function __construct(fs_plugin_manager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * @return array{enable?: string, download_zip?: array{path: string, filename: string}, errors: string[], messages: string[], advices: string[]}
     */
    public function handle(): array
    {
        $result = ['errors' => [], 'messages' => [], 'advices' => []];

        if (filter_input(INPUT_GET, 'restore_backup')) {
            $pluginName = basename((string) filter_input(INPUT_GET, 'restore_backup'));
            if (in_array($pluginName, $this->pluginManager->enabled())) {
                $this->pluginManager->disable($pluginName);
            }
            if ($this->pluginManager->restore_backup($pluginName)) {
                $result['messages'][] = 'Plugin <b>' . $pluginName . '</b> restaurado correctamente desde el backup.';
            }
        } elseif (filter_input(INPUT_POST, 'cancel_pending_install')) {
            if (isset($_SESSION['pending_plugin'])) {
                if (file_exists($_SESSION['pending_plugin']['temp_file'])) {
                    unlink($_SESSION['pending_plugin']['temp_file']);
                }
                unset($_SESSION['pending_plugin']);
            }
        } elseif (filter_input(INPUT_GET, 'download_plugin')) {
            $pluginName = basename((string) filter_input(INPUT_GET, 'download_plugin'));
            $pluginPath = FS_FOLDER . '/plugins/' . $pluginName;

            if (!file_exists($pluginPath) || !is_dir($pluginPath)) {
                $result['errors'][] = 'El plugin <b>' . $pluginName . '</b> no existe.';
                return $result;
            }

            $zipFilename = $pluginName . '.zip';
            $tmpDir = $this->ensureTmpDirectory($result);
            if ($tmpDir === null) {
                return $result;
            }

            $zipPath = $tmpDir . '/' . $zipFilename;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $result['errors'][] = 'Error al crear el archivo ZIP para el plugin <b>' . $pluginName . '</b>.';
                return $result;
            }

            $this->addFilesToZip($zip, $pluginPath, $pluginName);
            $zip->close();

            if (!file_exists($zipPath)) {
                $result['errors'][] = 'Error al crear el archivo ZIP.';
                return $result;
            }

            $result['enable'] = $pluginName;
            $result['download_zip'] = ['path' => $zipPath, 'filename' => $zipFilename];
        } elseif (filter_input(INPUT_POST, 'confirm_overwrite') && isset($_SESSION['pending_plugin'])) {
            $pending = $_SESSION['pending_plugin'];

            if (empty($pending['temp_file']) || !file_exists($pending['temp_file'])) {
                $result['errors'][] = 'El archivo temporal del plugin no se encuentra. Vuelve a subir el ZIP.';
                unset($_SESSION['pending_plugin']);
                return $result;
            }

            $installResult = $this->pluginManager->install($pending['temp_file'], $pending['name'] . '.zip', true);

            if (file_exists($pending['temp_file'])) {
                unlink($pending['temp_file']);
            }

            unset($_SESSION['pending_plugin']);

            if ($installResult) {
                $installedPlugin = is_string($installResult) && $installResult !== '' ? $installResult : $pending['name'];
                $result['messages'][] = 'Plugin <b>' . $installedPlugin . '</b> instalado correctamente. El plugin anterior se guardó como backup.';
            } else {
                $result['errors'][] = 'No se pudo instalar el plugin <b>' . $pending['name'] . '</b> tras confirmar la sobrescritura.';
            }
        } elseif (!empty($_FILES['fplugin']['tmp_name']) && is_uploaded_file($_FILES['fplugin']['tmp_name'])) {
            $pluginInfo = $this->pluginManager->detect_plugin_from_zip($_FILES['fplugin']['tmp_name']);

            if (!$pluginInfo) {
                $result['errors'][] = 'Error al leer el archivo ZIP del plugin.';
                return $result;
            }

            $pluginName = $pluginInfo['name'];
            $newVersion = $pluginInfo['version'];
            $existingPlugin = $this->pluginManager->check_plugin_exists($pluginName);

            if ($existingPlugin) {
                $tmpDir = $this->ensureTmpDirectory($result);
                if ($tmpDir === null) {
                    return $result;
                }

                $tempFile = $tmpDir . '/plugin_pending_install_' . session_id() . '_' . bin2hex(random_bytes(8)) . '.zip';
                if (!move_uploaded_file($_FILES['fplugin']['tmp_name'], $tempFile)) {
                    $result['errors'][] = 'No se pudo guardar temporalmente el archivo del plugin para confirmar la sobreescritura.';
                    return $result;
                }

                $_SESSION['pending_plugin'] = [
                    'name' => $pluginName,
                    'new_version' => $newVersion,
                    'current_version' => $existingPlugin['version'],
                    'temp_file' => $tempFile,
                ];

                $result['advices'][] = 'El plugin <b>' . $pluginName . '</b> ya existe. Se requiere confirmación para sobrescribir.';
            } else {
                $installResult = $this->pluginManager->install($_FILES['fplugin']['tmp_name'], $_FILES['fplugin']['name'], false);
                if ($installResult) {
                    $installedPlugin = is_string($installResult) && $installResult !== '' ? $installResult : $pluginName;
                    $result['messages'][] = 'Plugin <b>' . $installedPlugin . '</b> instalado correctamente.';
                } else {
                    $result['errors'][] = 'No se pudo instalar el plugin <b>' . $pluginName . '</b> desde el ZIP subido.';
                }
            }
        }

        return $result;
    }

    private function ensureTmpDirectory(array &$result): ?string
    {
        $tmpDir = FS_FOLDER . '/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            $result['errors'][] = 'No se pudo crear el directorio temporal para la operación del plugin.';
            return null;
        }

        if (!is_writable($tmpDir)) {
            $result['errors'][] = 'El directorio temporal de plugins no tiene permisos de escritura.';
            return null;
        }

        return $tmpDir;
    }

    private function addFilesToZip(ZipArchive $zip, string $sourcePath, string $basePath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $relativePath = substr($file->getPathname(), strlen(rtrim($sourcePath, '/')) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $localPath = $basePath . '/' . $relativePath;
            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } elseif ($file->isFile()) {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }
    }
}
