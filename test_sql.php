<?php
define('FS_DB_TYPE', 'MYSQL');
define('FS_DB_HOST', 'db');
define('FS_DB_NAME', 'db');
define('FS_DB_USER', 'db');
define('FS_DB_PASS', 'db');
define('FS_DB_PORT', '3306');
define('FS_FOREIGN_KEYS', true);

require_once "vendor/autoload.php";
require_once "base/fs_core_log.php";
require_once "base/fs_db_engine.php";
require_once "base/fs_mysql.php";
require_once "base/fs_model.php";

class TempModel extends fs_model {
    public function __construct() {
        self::$base_dir['fs_logs'] = '';
    }
    public function get_xml($table) {
        $cols = [];
        $cons = [];
        $this->get_xml_table($table, $cols, $cons);
        return ['columns' => $cols, 'constraints' => $cons];
    }
    public function delete() { return false; }
    public function exists() { return false; }
    public function save() { return false; }
}

$model = new TempModel();
$xml_data = $model->get_xml("fs_logs");
$cols = $xml_data["columns"];
$cons = $xml_data["constraints"];

$db = new fs_mysql();
echo "FS_DB_TYPE: " . FS_DB_TYPE . "\n";
echo "SQL: " . $db->generate_table("fs_logs", $cols, $cons) . "\n";
