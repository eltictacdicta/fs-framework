<?php

namespace FacturaScripts\Dinamic\Model;

class User
{
    public $idempresa;
    public $nick;
    public $admin = true; // Temporary generic fallback
    public $fs_user_legacy;

    public function __construct()
    {
        // Try to load current user from legacy session/cookie
        if (isset($_COOKIE['fsNick'])) {
            $this->nick = $_COOKIE['fsNick'];
            // Load legacy user to get more data if needed
            if (class_exists('fs_user')) {
                $tempUser = new \fs_user();
                $loaded = $tempUser->get($this->nick);
                if ($loaded) {
                    $this->fs_user_legacy = $loaded;
                    $this->admin = $loaded->admin;
                }
            }
        }
    }
    public function __get($name)
    {
        if (isset($this->fs_user_legacy) && isset($this->fs_user_legacy->$name)) {
            return $this->fs_user_legacy->$name;
        }
        return null;
    }

    public function __call($name, $arguments)
    {
        if (isset($this->fs_user_legacy) && method_exists($this->fs_user_legacy, $name)) {
            return call_user_func_array([$this->fs_user_legacy, $name], $arguments);
        }
        return null;
    }
}
