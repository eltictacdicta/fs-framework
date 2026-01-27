<?php

namespace FacturaScripts\Core\Base;

/**
 * Bridge for FacturaScripts\Core\Base\DataBase
 */
class DataBase
{
    private $db;

    public function __construct()
    {
        if (class_exists('fs_db2')) {
            $this->db = new \fs_db2();
        }
    }

    public function connect(): bool
    {
        return $this->db ? $this->db->connect() : false;
    }

    public function connected(): bool
    {
        return $this->db ? $this->db->connected() : false;
    }

    public function exec(string $sql): bool
    {
        return $this->db ? $this->db->exec($sql) : false;
    }

    public function select(string $sql): array
    {
        return $this->db ? $this->db->select($sql) : [];
    }

    public function close(): bool
    {
        return $this->db ? $this->db->close() : true;
    }
}
