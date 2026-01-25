<?php

namespace FacturaScripts\Core;

class Tools
{
    public static function log()
    {
        return new class {
            public function error($msg, $params = [])
            {
                error_log('FS Error: ' . $msg);
            }
            public function warning($msg, $params = [])
            {
                error_log('FS Warning: ' . $msg);
            }
            public function notice($msg, $params = [])
            {
                error_log('FS Notice: ' . $msg);
            }
        };
    }

    public static function config($key, $default = null)
    {
        // Map to legacy constants or DB config
        if ($key === 'db_type')
            return defined('FS_DB_TYPE') ? FS_DB_TYPE : 'mysql';
        if ($key === 'db_port')
            return defined('FS_DB_PORT') ? FS_DB_PORT : 3306;
        if ($key === 'db_name')
            return defined('FS_DB_NAME') ? FS_DB_NAME : '';
        if ($key === 'db_user')
            return defined('FS_DB_USER') ? FS_DB_USER : '';
        if ($key === 'db_pass')
            return defined('FS_DB_PASS') ? FS_DB_PASS : '';
        if ($key === 'db_host')
            return defined('FS_DB_HOST') ? FS_DB_HOST : '';

        return $default;
    }

    public static function folder($path = '', $sub = '', $sub2 = '')
    {
        $fullChange = FS_FOLDER;
        if ($path)
            $fullChange .= '/' . $path;
        if ($sub)
            $fullChange .= '/' . $sub;
        if ($sub2)
            $fullChange .= '/' . $sub2;
        return $fullChange;
    }

    public static function folderCheckOrCreate($path)
    {
        if (!file_exists($path)) {
            return mkdir($path, 0777, true);
        }
        return true;
    }

    public static function folderScan($path)
    {
        return scandir($path);
    }
}
