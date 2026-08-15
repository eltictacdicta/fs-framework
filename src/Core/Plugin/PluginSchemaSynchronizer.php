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
     * Clases legacy que los hooks Init suelen usar durante activación/upgrade.
     *
     * @var list<string>
     */
    private const INIT_MIGRATION_LEGACY_CLASSES = [
        'fs_settings',
        'fs_cache',
        'fs_db2',
    ];

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

        $tableDir = rtrim($pluginsRoot, '/\\') . '/' . $pluginName . '/model/table';
        $tableNames = $this->syncXmlTables($tableDir, $result);

        if ($tableNames !== []) {
            $this->refreshPluginModels($pluginName, $pluginsRoot, $tableNames, $result);
        }

        // Tras crear/sincronizar tablas y sembrar install() de modelos vacíos.
        if ($result['success']) {
            $this->runInitMigrations($pluginName, $result);
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

        $this->ensureInitMigrationRuntime();

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

        if (!class_exists('\fs_schema', false)) {
            require_once $this->frameworkPath('base/fs_schema.php');
        }

        $syncResult = \fs_schema::syncPluginTables($tableDir);

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
        if (!class_exists('\fs_model', false)) {
            require_once $this->frameworkPath('base/fs_model.php');
        }

        \fs_model::forgetCheckedTables($tableNames);
        $this->ensureModelAutoloader();

        $pluginPath = rtrim($pluginsRoot, '/\\') . '/' . $pluginName;
        foreach (['model', 'model/core'] as $subdir) {
            $this->refreshModelsInDirectory($pluginPath . '/' . $subdir, $pluginName, $result);
        }
    }

    /**
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     */
    private function refreshModelsInDirectory(string $dir, string $pluginName, array &$result): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $this->refreshModelClassFromFile($file, $pluginName, $result);
        }
    }

    /**
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     */
    private function refreshModelClassFromFile(string $file, string $pluginName, array &$result): void
    {
        $className = basename($file, '.php');
        if (!class_exists($className, false)) {
            require_once $file;
        }

        if (!class_exists($className, false) || !is_subclass_of($className, \fs_model::class)) {
            return;
        }

        try {
            $model = new $className();
            if ($model instanceof \fs_model && $model->seed_if_empty()) {
                $result['changes'][] = $className . ': datos por defecto insertados';
            }
        } catch (\Throwable $e) {
            $message = $className . ': ' . $e->getMessage();
            $result['errors'][] = $message;
            $result['success'] = false;
            error_log('PluginSchemaSynchronizer: model refresh failed for ' . $pluginName . ': ' . $message);
        }
    }

    private function ensureModelAutoloader(): void
    {
        if (class_exists('\fs_model_autoloader', false)) {
            \fs_model_autoloader::refreshModelDirs();

            return;
        }

        $autoloaderPath = $this->frameworkPath('base/fs_model_autoloader.php');
        if (!file_exists($autoloaderPath)) {
            return;
        }

        require_once $autoloaderPath;
        if (class_exists('\fs_model_autoloader', false)) {
            \fs_model_autoloader::register(false);
            \fs_model_autoloader::refreshModelDirs();
        }
    }

    private function frameworkPath(string $relativePath): string
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';

        return rtrim($folder, '/\\') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Prepara el runtime legacy mínimo antes de ejecutar Init::update()/upgrade().
     * Los plugins pueden usar clases base del framework sin require manual.
     */
    private function ensureInitMigrationRuntime(): void
    {
        if (!class_exists('\fs_autoload', false)) {
            require_once $this->frameworkPath('base/fs_autoload.php');
        } elseif (!\fs_autoload::isRegistered()) {
            \fs_autoload::register(defined('FS_FOLDER') ? FS_FOLDER : null);
        }

        foreach (self::INIT_MIGRATION_LEGACY_CLASSES as $legacyClass) {
            if (!class_exists($legacyClass, false)) {
                class_exists($legacyClass);
            }
        }
    }
}
