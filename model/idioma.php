<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Core language registry for content multi-language.
 *
 * Owns the durable lifecycle invariants (CI-01…CI-05): a total, deterministic
 * default resolution, exactly one active default, deactivation/deletion guards,
 * and an idempotent single-row seed. The behavioral conventions mirror the
 * catalogo_core `catalogo_idioma` model, but this core class is intentionally
 * global (`model/idioma.php`) per design D1 and never references any plugin
 * table (D12).
 */
class idioma extends \fs_model
{
    public const TABLE = 'idiomas';
    public const DEFAULT_CODE = 'es';

    /** @var string|null Primary key, 2-5 chars. */
    public $codidioma;

    /** @var string Display name, 1-50 chars. */
    public $nombre;

    /** @var bool Whether the language is available for use. */
    public $activo;

    /** @var bool Whether this is the configured default language. */
    public $por_defecto;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

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

    /**
     * Seed SQL run when the schema pass creates the table (D8, defense in depth).
     */
    protected function install()
    {
        return 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES '
            . "('" . self::DEFAULT_CODE . "', 'Español', TRUE, TRUE);";
    }

    /**
     * Inserts the single default row only when the registry is empty (CI-05).
     */
    public function ensure_defaults(): void
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) > 0) {
            return;
        }

        $this->db->exec($this->install());
    }

    public function get($cod)
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($cod) . ';'
        );
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_default()
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE por_defecto = TRUE AND activo = TRUE ORDER BY codidioma ASC LIMIT 1;'
        );
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * Total, deterministic default resolution (CI-01): an active flagged default
     * wins, otherwise the lowest active `codidioma`, and `DEFAULT_CODE` is the
     * terminal fallback.
     */
    public function defaultCode(): string
    {
        $default = $this->get_default();
        if ($default) {
            return (string) $default->codidioma;
        }

        return $this->lowestActiveCode() ?? self::DEFAULT_CODE;
    }

    /**
     * Lowest active `codidioma` in ascending string order, or null when no row
     * is active. Deterministic regardless of row insertion or query order.
     */
    private function lowestActiveCode(): ?string
    {
        $candidates = $this->db->select(
            'SELECT codidioma FROM ' . $this->table_name
            . ' WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;'
        );
        if (!$candidates) {
            return null;
        }

        return (string) $candidates[0]['codidioma'];
    }

    /**
     * Makes the given language the only active default (CI-03): a flag flip that
     * touches no content/translation row.
     */
    public function set_default(string $codidioma): bool
    {
        $target = $this->get($codidioma);
        if (!$target || !$target->activo) {
            $this->new_error_msg('El idioma indicado no existe o está inactivo.');
            return false;
        }

        $this->db->begin_transaction();

        $ok = $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE por_defecto = TRUE;')
            && $this->db->exec(
                'UPDATE ' . $this->table_name . ' SET por_defecto = TRUE, activo = TRUE WHERE codidioma = '
                . $this->var2str($codidioma) . ';'
            );

        if (!$ok) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    /**
     * Restores the "exactly one active default" invariant after a mutation.
     *
     * Split into PHP statements because a single UPDATE ... WHERE (SELECT ...
     * FROM the same table) raises MySQL error 1093.
     */
    public function normalize_default(): void
    {
        $this->db->exec(
            'UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE por_defecto = TRUE AND activo = FALSE;'
        );

        $count = $this->db->select(
            'SELECT COUNT(*) as total FROM ' . $this->table_name . ' WHERE por_defecto = TRUE;'
        );
        if ($count && intval($count[0]['total']) === 1) {
            return;
        }

        $candidates = $this->db->select(
            'SELECT codidioma FROM ' . $this->table_name
            . ' WHERE por_defecto = TRUE AND activo = TRUE ORDER BY codidioma ASC LIMIT 1;'
        );

        // When duplicates exist, keep the lowest flagged active code; when the
        // flag was cleared entirely, re-flag the lowest active code. Either way
        // the resolved default is unchanged (CI-03).
        $target = $candidates ? (string) $candidates[0]['codidioma'] : $this->lowestActiveCode();
        if ($target === null) {
            return;
        }
        $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE por_defecto = TRUE;');
        $this->db->exec(
            'UPDATE ' . $this->table_name . ' SET por_defecto = TRUE WHERE codidioma = '
            . $this->var2str($target) . ';'
        );
    }

    public function exists()
    {
        if (is_null($this->codidioma)) {
            return false;
        }

        return $this->db->select(
            'SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';'
        );
    }

    public function test()
    {
        $this->codidioma = $this->no_html(strtolower(trim((string) $this->codidioma)));
        $this->nombre = $this->no_html($this->nombre);

        if (mb_strlen($this->codidioma) < 2 || mb_strlen($this->codidioma) > 5) {
            $this->new_error_msg('Código de idioma no válido. Deben ser entre 2 y 5 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg('Nombre de idioma no válido.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            $current = $this->get($this->codidioma);
            if ($current && $current->por_defecto && !$this->activo) {
                $this->new_error_msg('No se puede desactivar el idioma por defecto.');
                return false;
            }
        }

        $this->db->begin_transaction();

        if ($this->por_defecto) {
            $this->db->exec(
                'UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE codidioma != '
                . $this->var2str($this->codidioma) . ';'
            );
            $this->activo = true;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'nombre = ' . $this->var2str($this->nombre)
                . ', activo = ' . $this->var2str($this->activo)
                . ', por_defecto = ' . $this->var2str($this->por_defecto)
                . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES ('
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->por_defecto) . ');';
        }

        if (!$this->db->exec($sql)) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    public function delete()
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) <= 1) {
            $this->new_error_msg('No se puede eliminar el último idioma.');
            return false;
        }

        if ($this->por_defecto) {
            $this->new_error_msg('No se puede eliminar el idioma por defecto.');
            return false;
        }

        $this->db->begin_transaction();

        $ok = $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';'
        );

        if (!$ok) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' ORDER BY codidioma ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function all_activos()
    {
        $list = [];
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY codidioma ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }
}
