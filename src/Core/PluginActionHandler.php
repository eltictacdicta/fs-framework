<?php

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
     * @return array{enable?: string, errors: string[], messages: string[], advices: string[]}
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
            $zipPath = FS_FOLDER . '/tmp/' . $zipFilename;

            if (!file_exists(FS_FOLDER . '/tmp')) {
                mkdir(FS_FOLDER . '/tmp', 0777, true);
            }

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
                $result['messages'][] = 'Plugin <b>' . $installResult . '</b> instalado correctamente. El plugin anterior se guardó como backup.';
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
                $tempFile = FS_FOLDER . '/tmp/plugin_pending_install_' . session_id() . '_' . bin2hex(random_bytes(8)) . '.zip';
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
                $this->pluginManager->install($_FILES['fplugin']['tmp_name'], $_FILES['fplugin']['name'], false);
            }
        }

        return $result;
    }

    private function addFilesToZip(ZipArchive $zip, string $sourcePath, string $basePath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $localPath = $basePath . '/' . $file->getFilename();
            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } elseif ($file->isFile()) {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }
    }
}
