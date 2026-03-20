<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class cliente_facturacion extends fs_extended_model
{
    public function __construct($data = false)
    {
        parent::__construct('clientesfacturacion', $data);
        $this->sync_legacy_rows();
    }

    public function model_class_name()
    {
        return 'cliente_facturacion';
    }

    public function primary_column()
    {
        return 'codcliente';
    }

    protected function install()
    {
        return $this->legacy_sync_sql();
    }

    private function sync_legacy_rows(): void
    {
        if (!$this->db->table_exists('clientes') || !$this->db->table_exists($this->table_name())) {
            return;
        }

        $cacheKey = 'clientesfacturacion_legacy_sync';
        if ($this->cache->get($cacheKey)) {
            return;
        }

        $this->db->exec($this->legacy_sync_sql());
        $this->cache->set($cacheKey, true, 300);
    }

    private function legacy_sync_sql(): string
    {
        return "INSERT INTO " . $this->table_name() . " (codcliente,codpago,coddivisa,codserie,codagente,codtarifa,codproveedor) "
            . "SELECT c.codcliente,c.codpago,c.coddivisa,c.codserie,c.codagente,c.codtarifa,c.codproveedor "
            . "FROM clientes c "
            . "WHERE NOT EXISTS (SELECT 1 FROM " . $this->table_name() . " cf WHERE cf.codcliente = c.codcliente);";
    }
}