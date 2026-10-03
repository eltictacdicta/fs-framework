<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Support;

require_once FS_FOLDER . '/model/idioma.php';

/**
 * DB-free `idioma` subclass: it skips the fs_model constructor, injects the
 * in-memory fake and collects the reported errors. Mirrors the catalogo_core
 * `FakeCatalogoIdioma` pattern.
 */
final class FakeIdioma extends \idioma
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \idioma::TABLE;

        if ($data) {
            $this->codidioma = $data['codidioma'];
            $this->nombre = $data['nombre'];
            $this->activo = $this->str2bool($data['activo']);
            $this->por_defecto = $this->str2bool($data['por_defecto']);
        } else {
            $this->codidioma = null;
            $this->nombre = '';
            $this->activo = true;
            $this->por_defecto = false;
        }
    }

    public function useFakeDb(IdiomaModelFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    public function install(): string
    {
        return parent::install();
    }

    public function ensure_defaults(): void
    {
        parent::ensure_defaults();
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}
