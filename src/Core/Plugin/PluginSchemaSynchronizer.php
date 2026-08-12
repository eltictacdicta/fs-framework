<?php

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

use FSFramework\Core\Template\InitClass;

/**
 * Orquesta migraciones de plugins al activar o actualizar:
 * - InitClass::update() / Init::upgrade() (legacy)
 * - fs_schema::syncPluginTables() desde XML
 * - check_table() / install() de modelos legacy del plugin
 */
final class PluginSchemaSynchronizer
{
    /**
     * @return array{success: bool, changes: list<string>, errors: list<string>}
     */
    public function synchronize(string $pluginName, string $pluginsRoot): array
    {
        $result = [
            'success' => true,
            'changes' => [],
            'errors' => [],
        ];

        $this->runInitMigrations($pluginName, $result);

        $tableDir = rtrim($pluginsRoot, '/\\') . '/' . $pluginName . '/model/table';
        $tableNames = $this->syncXmlTables($tableDir, $result);

        if ($tableNames !== []) {
            $this->refreshPluginModels($pluginName, $pluginsRoot, $tableNames, $result);
        }

        return $result;
    }

    /**
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     */
    private function runInitMigrations(string $pluginName, array &$result): void
    {
        $initClass = '\\FSFramework\\Plugins\\' . $pluginName . '\\Init';
        if (!class_exists($initClass)) {
            return;
        }

        try {
            if (is_subclass_of($initClass, InitClass::class)) {
                $init = new $initClass();
                $init->update();
                $result['changes'][] = 'Init::update() ejecutado';

                return;
            }

            if (is_callable([$initClass, 'upgrade'])) {
                $initClass::upgrade();
                $result['changes'][] = 'Init::upgrade() ejecutado';

                return;
            }

            $init = new $initClass();
            if (method_exists($init, 'update')) {
                $init->update();
                $result['changes'][] = 'Init::update() ejecutado';
            }
        } catch (\Throwable $e) {
            $message = 'Init migration: ' . $e->getMessage();
            $result['errors'][] = $message;
            $result['success'] = false;
            error_log('PluginSchemaSynchronizer: ' . $pluginName . ': ' . $message);
        }
    }

    /**
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     *
     * @return list<string>
     */
    private function syncXmlTables(string $tableDir, array &$result): array
    {
        if (!is_dir($tableDir)) {
            return [];
        }

        if (!class_exists('fs_schema', false)) {
            require_once $this->frameworkPath('base/fs_schema.php');
        }

        $syncResult = fs_schema::syncPluginTables($tableDir);

        if (isset($syncResult['error'])) {
            $result['errors'][] = (string) $syncResult['error'];
            $result['success'] = false;

            return [];
        }

        foreach ($syncResult['changes'] as $change) {
            if (is_string($change) && $change !== '') {
                $result['changes'][] = $change;
            }
        }

        foreach ($syncResult['errors'] as $error) {
            $result['errors'][] = (string) $error;
            $result['success'] = false;
        }

        return $syncResult['tables'];
    }

    /**
     * @param list<string> $tableNames
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     */
    private function refreshPluginModels(
        string $pluginName,
        string $pluginsRoot,
        array $tableNames,
        array &$result
    ): void {
        if (!class_exists('fs_model', false)) {
            require_once $this->frameworkPath('base/fs_model.php');
        }

        fs_model::forgetCheckedTables($tableNames);
        $this->ensureModelAutoloader();

        $pluginPath = rtrim($pluginsRoot, '/\\') . '/' . $pluginName;
        foreach (['model', 'model/core'] as $subdir) {
            $dir = $pluginPath . '/' . $subdir;
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.php');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $className = basename($file, '.php');
                if (!class_exists($className, false)) {
                    require_once $file;
                }

                if (!class_exists($className, false) || !is_subclass_of($className, 'fs_model')) {
                    continue;
                }

                try {
                    new $className();
                } catch (\Throwable $e) {
                    $message = $className . ': ' . $e->getMessage();
                    $result['errors'][] = $message;
                    $result['success'] = false;
                    error_log('PluginSchemaSynchronizer: model refresh failed for ' . $pluginName . ': ' . $message);
                }
            }
        }
    }

    private function ensureModelAutoloader(): void
    {
        if (class_exists('fs_model_autoloader', false)) {
            fs_model_autoloader::refreshModelDirs();

            return;
        }

        $autoloaderPath = $this->frameworkPath('base/fs_model_autoloader.php');
        if (!file_exists($autoloaderPath)) {
            return;
        }

        require_once $autoloaderPath;
        if (class_exists('fs_model_autoloader', false)) {
            fs_model_autoloader::register(false);
            fs_model_autoloader::refreshModelDirs();
        }
    }

    private function frameworkPath(string $relativePath): string
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';

        return rtrim($folder, '/\\') . '/' . ltrim($relativePath, '/');
    }
}
