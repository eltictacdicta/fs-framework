<?php
/**
 * Standalone Backup Manager for FSFramework
 * This class is designed to work independently from the framework,
 * allowing it to be used in older versions during the update process.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */
class fs_backup_manager
{
    const BACKUP_DIR = 'update-and-backup';

    /**
     * @var string
     */
    private $fsRoot;

    /**
     * @var string
     */
    private $backupPath;

    /**
     * @var array
     */
    private $errors = array();

    /**
     * @var array
     */
    private $messages = array();

    /**
     * Directories to exclude from file backups.
     * @var array
     */
    private $excludedDirs = array(
        'backups',
        'tmp',
        '.git',
        '.idea',
        'node_modules',
    );

    /**
     * Constructor.
     *
     * @param string|null $fsRoot The root directory of FSFramework.
     */
    public function __construct($fsRoot = null)
    {
        if ($fsRoot !== null) {
            $this->fsRoot = $fsRoot;
        } elseif (defined('FS_FOLDER')) {
            $this->fsRoot = FS_FOLDER;
        } else {
            $this->fsRoot = dirname(__DIR__);
        }

        // Store backups in backups/data/ to keep library and data separate
        $this->backupPath = $this->fsRoot . DIRECTORY_SEPARATOR . self::BACKUP_DIR . DIRECTORY_SEPARATOR . 'data';
        $this->ensureBackupDirectoryExists();
    }

    /**
     * Create the backup directory if it doesn't exist.
     */
    private function ensureBackupDirectoryExists()
    {
        if (!is_dir($this->backupPath)) {
            if (!@mkdir($this->backupPath, 0755, true)) {
                $this->errors[] = "No se puede crear el directorio de copias de seguridad: " . $this->backupPath;
                return;
            }
            // Create security files
            file_put_contents($this->backupPath . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            file_put_contents($this->backupPath . '/index.php', "<?php\n// No directory listing\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
        }
    }

    /**
     * Get errors.
     * @return array
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Get messages.
     * @return array
     */
    public function get_messages()
    {
        return $this->messages;
    }

    /**
     * Get the backup directory path.
     * @return string
     */
    public function get_backup_path()
    {
        return $this->backupPath;
    }

    /**
     * Create a complete backup (database + files).
     *
     * @param string $customName Optional custom name for the backup.
     * @return array Results with 'database', 'files', and 'complete' keys.
     */
    public function create_backup($customName = '')
    {
        $timestamp = date('Y-m-d_H-i-s');
        $baseName = $customName ? $customName : 'backup_' . $timestamp;

        $results = array(
            'database' => $this->create_database_backup($baseName . '_db'),
            'files' => $this->create_files_backup($baseName . '_files'),
        );

        if ($results['database']['success'] && $results['files']['success']) {
            $results['complete'] = array(
                'success' => true,
                'backup_name' => $baseName,
                'database_file' => $results['database']['file'],
                'files_file' => $results['files']['file'],
                'created_at' => date('Y-m-d H:i:s'),
            );
            $this->save_metadata($results['complete']);
            $this->messages[] = "Copia de seguridad creada correctamente: " . $baseName;
        } else {
            $results['complete'] = array('success' => false, 'backup_name' => $baseName);
        }

        // Optionally clean old backups
        $this->clean_old_backups(5);

        return $results;
    }

    /**
     * Create a database backup.
     *
     * @param string $customName
     * @return array
     */
    public function create_database_backup($customName = '')
    {
        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : 'db_backup_' . $timestamp) . '.sql.gz';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        // Get DB credentials - compatible with older and newer versions
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        $command = sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s 2>&1 | gzip > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($filePath)
        );

        $output = array();
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($filePath) || filesize($filePath) === 0) {
            $this->errors[] = "Error al crear la copia de la base de datos: " . implode("\n", $output);
            return array('success' => false, 'file' => null, 'error' => implode("\n", $output));
        }

        $this->messages[] = "Copia de base de datos creada: " . $fileName;
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $filePath,
            'size' => filesize($filePath),
            'size_formatted' => $this->format_bytes(filesize($filePath)),
        );
    }

    /**
     * Create a files backup.
     *
     * @param string $customName
     * @return array
     */
    public function create_files_backup($customName = '')
    {
        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return array('success' => false, 'file' => null, 'error' => 'ZIP extension not loaded');
        }

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : 'files_backup_' . $timestamp) . '.zip';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        $zip = new ZipArchive();
        $result = $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            $this->errors[] = "No se puede crear el archivo ZIP: código de error " . $result;
            return array('success' => false, 'file' => null, 'error' => 'ZipArchive open failed');
        }

        $fileCount = $this->add_directory_to_zip($zip, $this->fsRoot);

        if (!$zip->close()) {
            $this->errors[] = "Error al cerrar el archivo ZIP.";
            return array('success' => false, 'file' => null, 'error' => 'ZipArchive close failed');
        }

        if ($fileCount === 0) {
            $this->errors[] = "No se añadieron archivos al backup.";
            @unlink($filePath);
            return array('success' => false, 'file' => null, 'error' => 'No files added');
        }

        $this->messages[] = "Copia de archivos creada: " . $fileName . " (" . $fileCount . " archivos)";
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $filePath,
            'size' => filesize($filePath),
            'size_formatted' => $this->format_bytes(filesize($filePath)),
            'file_count' => $fileCount,
        );
    }

    /**
     * Recursively add a directory to a ZipArchive.
     *
     * @param ZipArchive $zip
     * @param string $sourceDir
     * @return int Number of files added.
     */
    private function add_directory_to_zip($zip, $sourceDir)
    {
        $fileCount = 0;
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relPath = substr($filePath, strlen($this->fsRoot) + 1);

            // Check exclusions
            if ($this->should_exclude_file($relPath)) {
                continue;
            }

            $zip->addFile($filePath, $relPath);
            $fileCount++;

            // Prevent timeout
            if ($fileCount % 500 === 0) {
                @set_time_limit(300);
            }
        }

        return $fileCount;
    }

    /**
     * Check if a file should be excluded from the backup.
     *
     * @param string $relativePath
     * @return bool
     */
    private function should_exclude_file($relativePath)
    {
        foreach ($this->excludedDirs as $excludedDir) {
            if (strpos($relativePath, $excludedDir . DIRECTORY_SEPARATOR) === 0 || $relativePath === $excludedDir) {
                return true;
            }
        }
        return false;
    }

    /**
     * List available backups.
     *
     * @return array
     */
    public function list_backups()
    {
        $backups = array();
        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        $files = scandir($this->backupPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php' || $file === 'metadata.json') {
                continue;
            }

            $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $backups[] = array(
                'name' => $file,
                'size' => filesize($filePath),
                'size_formatted' => $this->format_bytes(filesize($filePath)),
                'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                'timestamp' => filemtime($filePath),
                'type' => $this->get_backup_type($file),
                'path' => $filePath,
            );
        }

        // Sort by date (most recent first)
        usort($backups, array($this, 'sort_by_timestamp'));

        return $backups;
    }

    /**
     * Sort callback for backups by timestamp descending.
     * @param array $a
     * @param array $b
     * @return int
     */
    private function sort_by_timestamp($a, $b)
    {
        return $b['timestamp'] - $a['timestamp'];
    }

    /**
     * Determine backup type from filename.
     *
     * @param string $filename
     * @return string
     */
    private function get_backup_type($filename)
    {
        if (substr($filename, -7) === '.sql.gz' || strpos($filename, '_db') !== false) {
            return 'database';
        }
        if (substr($filename, -4) === '.zip' || strpos($filename, '_files') !== false) {
            return 'files';
        }
        return 'unknown';
    }

    /**
     * Delete a backup file.
     *
     * @param string $filename
     * @return bool
     */
    public function delete_backup($filename)
    {
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . basename($filename);

        if (!file_exists($filePath)) {
            $this->errors[] = "El archivo de copia no existe: " . $filename;
            return false;
        }

        if (!@unlink($filePath)) {
            $this->errors[] = "No se puede eliminar el archivo: " . $filename;
            return false;
        }

        $this->messages[] = "Copia eliminada: " . $filename;
        return true;
    }

    /**
     * Clean old backups, keeping only a specified number.
     *
     * @param int $keepCount
     * @return int Number of files deleted.
     */
    public function clean_old_backups($keepCount = 5)
    {
        $backups = $this->list_backups();
        if (count($backups) <= $keepCount) {
            return 0;
        }

        $deleted = 0;
        $toDelete = array_slice($backups, $keepCount);

        foreach ($toDelete as $backup) {
            if ($this->delete_backup($backup['name'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Create a backup before an update.
     * This is a convenience method to be called from the updater.
     *
     * @param string $updateType 'core' or plugin name
     * @return array Backup result
     */
    public function create_pre_update_backup($updateType = 'core')
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $updateType);
        $backupName = 'pre_update_' . $safeName . '_' . date('Y-m-d_H-i-s');

        return $this->create_backup($backupName);
    }

    /**
     * Save backup metadata.
     *
     * @param array $backupData
     */
    private function save_metadata($backupData)
    {
        $metadataFile = $this->backupPath . DIRECTORY_SEPARATOR . 'metadata.json';
        $metadata = array();

        if (file_exists($metadataFile)) {
            $content = file_get_contents($metadataFile);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $metadata[$backupData['backup_name']] = $backupData;
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function format_bytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes = $bytes / 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
