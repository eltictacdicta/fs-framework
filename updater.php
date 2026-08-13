<?php
/**
 * Actualizador de FSFramework - Redirect / Auto-installer
 * 
 * Si el plugin system_updater está instalado, redirige a su controlador.
 * Si no está instalado, redirige al panel de control para descargarlo
 * automáticamente desde GitHub e instalarlo.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

const UPDATER_CONFIG_FILE = __DIR__ . '/config.php';

function updater_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function updater_self_update_redirect($success, $reason = '')
{
    if ($success) {
        error_log('system_updater: self-update finalized successfully.');
        updater_redirect('index.php?page=admin_updater&success=updater-self-update');
    }

    error_log('system_updater: self-update finalize FAILED' . ($reason !== '' ? ': ' . $reason : '.'));

    $fallback = file_exists(__DIR__ . '/plugins/system_updater/controller/admin_updater.php')
        ? 'index.php?page=admin_updater&error=updater-self-update'
        : 'index.php?page=admin_home';

    updater_redirect($fallback);
}

function updater_is_valid_staged_plugin($path)
{
    return is_dir($path)
        && file_exists($path . '/fsframework.ini')
        && file_exists($path . '/controller/admin_updater.php');
}

function updater_restore_backup($backupPath, $pluginPath)
{
    if (file_exists($backupPath) && !file_exists($pluginPath)) {
        @rename($backupPath, $pluginPath);
    }
}

function updater_read_self_update_manifest($manifestPath)
{
    if (!file_exists($manifestPath)) {
        return null;
    }

    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    if (!is_array($manifest) || empty($manifest['token']) || empty($manifest['staged_path'])) {
        @unlink($manifestPath);
        return null;
    }

    return $manifest;
}

function updater_path_is_within(string $realPath, string $realBaseDir): bool
{
    $normalizedPath = rtrim(str_replace('\\', '/', $realPath), '/');
    $normalizedBase = rtrim(str_replace('\\', '/', $realBaseDir), '/');

    if ($normalizedPath === $normalizedBase) {
        return true;
    }

    return strncmp($normalizedPath . '/', $normalizedBase . '/', strlen($normalizedBase) + 1) === 0;
}

function updater_resolve_valid_staged_path($stagedPath)
{
    $realStagedPath = realpath($stagedPath);
    $realTmpPath = realpath(__DIR__ . '/tmp');

    if ($realStagedPath === false || $realTmpPath === false || !updater_path_is_within($realStagedPath, $realTmpPath)) {
        return null;
    }

    if (!updater_is_valid_staged_plugin($realStagedPath)) {
        return null;
    }

    return $realStagedPath;
}

function updater_cleanup_self_update_artifacts($stagingRoot, $manifestPath)
{
    $realTmpPath = realpath(__DIR__ . '/tmp');
    if (!empty($stagingRoot) && file_exists($stagingRoot) && $realTmpPath !== false) {
        $realStagingRoot = realpath($stagingRoot);
        if ($realStagingRoot !== false && updater_path_is_within($realStagingRoot, $realTmpPath)) {
            fs_file_manager::del_tree($realStagingRoot);
        }
    }
    @unlink($manifestPath);
}

function updater_deploy_staged_plugin($realStagedPath, $pluginPath, $backupPath, $hasCurrentPlugin)
{
    if (file_exists($backupPath) && !fs_file_manager::del_tree($backupPath)) {
        return ['success' => false, 'reason' => 'No se pudo eliminar el backup anterior: ' . $backupPath];
    }

    if ($hasCurrentPlugin && !@rename($pluginPath, $backupPath)) {
        return ['success' => false, 'reason' => 'No se pudo mover el plugin actual a backup: ' . $pluginPath . ' → ' . $backupPath];
    }

    $deployed = @rename($realStagedPath, $pluginPath);
    if (!$deployed) {
        $deployed = fs_file_manager::recurse_copy($realStagedPath, $pluginPath);
    }

    if (!$deployed || !updater_is_valid_staged_plugin($pluginPath)) {
        error_log('system_updater: deploy failed. deployed=' . var_export($deployed, true) . ', valid=' . var_export(updater_is_valid_staged_plugin($pluginPath), true));
        if (is_dir($pluginPath)) {
            fs_file_manager::del_tree($pluginPath);
        }
        updater_restore_backup($backupPath, $pluginPath);

        return ['success' => false, 'reason' => 'No se pudo desplegar el plugin actualizado'];
    }

    return ['success' => true, 'reason' => ''];
}

function updater_finalize_self_update()
{
    define('FS_FOLDER', __DIR__);

    if (!file_exists(UPDATER_CONFIG_FILE)) {
        die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
    }

    require_once UPDATER_CONFIG_FILE;
    require_once __DIR__ . '/base/fs_file_manager.php';

    $manifestPath = __DIR__ . '/tmp/system_updater_self_update.json';
    $token = (string) filter_input(INPUT_GET, 'token');

    if ($token === '') {
        updater_self_update_redirect(false, 'Token vacío o manifiesto no encontrado (manifest=' . ($manifestPath) . ', token_empty=sí)');
    }

    $manifest = updater_read_self_update_manifest($manifestPath);
    if ($manifest === null) {
        updater_self_update_redirect(false, 'Manifiesto inválido o incompleto');
    }

    if (!hash_equals((string) $manifest['token'], $token)) {
        updater_self_update_redirect(false, 'Token no coincide');
    }

    $stagedPath = $manifest['staged_path'];
    $stagingRoot = $manifest['staging_root'] ?? dirname($stagedPath);
    $realStagedPath = updater_resolve_valid_staged_path($stagedPath);

    if ($realStagedPath === null) {
        $detail = 'stagedPath=' . $stagedPath
            . ', realStagedPath=' . var_export(realpath($stagedPath), true)
            . ', realTmpPath=' . var_export(realpath(__DIR__ . '/tmp'), true)
            . ', validPlugin=' . ((realpath($stagedPath) !== false && updater_is_valid_staged_plugin((string) realpath($stagedPath))) ? 'sí' : 'no');
        updater_cleanup_self_update_artifacts($stagingRoot, $manifestPath);
        updater_self_update_redirect(false, 'Staged path inválido: ' . $detail);
    }

    $pluginPath = __DIR__ . '/plugins/system_updater';
    $backupPath = __DIR__ . '/plugins/system_updater_back';
    $deployResult = updater_deploy_staged_plugin($realStagedPath, $pluginPath, $backupPath, is_dir($pluginPath));

    if (!$deployResult['success']) {
        updater_cleanup_self_update_artifacts($stagingRoot, $manifestPath);
        updater_self_update_redirect(false, $deployResult['reason']);
    }

    if (defined('FS_TMP_NAME')) {
        fs_file_manager::clear_all_template_cache();
    }

    updater_cleanup_self_update_artifacts($stagingRoot, $manifestPath);

    updater_self_update_redirect(true);
}

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $action = (string) filter_input(INPUT_GET, 'action');

    if ($action === 'finalize_system_updater_update') {
        updater_finalize_self_update();
    }

    // Verificar que existe config.php (sistema instalado)
    if (!file_exists(UPDATER_CONFIG_FILE)) {
        die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
    }

    // Verificar si el plugin system_updater está instalado
    if (file_exists(__DIR__ . '/plugins/system_updater/controller/admin_updater.php')) {
        // El plugin existe, redirigir directamente al actualizador
        updater_redirect('index.php?page=admin_updater');
    }

    // El plugin no existe, redirigir al panel de control para instalación automática
    // La descarga se hace desde admin_home (contexto autenticado) para seguridad
    updater_redirect('index.php?page=admin_home&install_system_updater=1');
}