---
name: fsframework-model-crud
description: >-
  Create a complete FSFramework model with XML schema definition, CRUD operations
  (test, save, delete, exists), Symfony validation, and PHPUnit tests. Use when
  adding a new database table, creating a model class, or when the user needs a
  full model with schema and tests.
---

# FSFramework Model CRUD

## Workflow

```
Model Creation:
- [ ] Step 1: Define XML schema in model/table/
- [ ] Step 2: Create model class with CRUD methods
- [ ] Step 3: Add validation (Symfony or manual)
- [ ] Step 4: Write tests
```

## Step 1: XML Schema

File: `model/table/{table_name}.xml` (table name is plural)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>nombre</nombre>
        <tipo>character varying(150)</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>email</nombre>
        <tipo>character varying(200)</tipo>
        <nulo>YES</nulo>
    </columna>
    <columna>
        <nombre>is_active</nombre>
        <tipo>boolean</tipo>
        <defecto>true</defecto>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>created_at</nombre>
        <tipo>timestamp</tipo>
        <nulo>YES</nulo>
    </columna>
    <restriccion>
        <nombre>mi_tabla_pkey</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
</tabla>
```

### Common Column Types

| Type | XML `<tipo>` |
|------|-------------|
| Auto-increment int | `serial` |
| Integer | `integer` |
| String (N chars) | `character varying(N)` |
| Text (unlimited) | `text` |
| Boolean | `boolean` |
| Decimal | `double precision` |
| Date | `date` |
| Timestamp | `timestamp` |
| Money | `double precision` |

## Step 2: Model Class

File: `model/{table_name_singular}.php`

```php
<?php

class mi_modelo extends fs_model
{
    public $id;
    public $nombre;
    public $email;
    public $is_active;
    public $created_at;
    public $updated_at;

    public function __construct($data = false)
    {
        parent::__construct('mi_modelos');
        if ($data) {
            $this->id = $data['id'];
            $this->nombre = $data['nombre'];
            $this->email = $data['email'] ?? null;
            $this->is_active = $this->str2bool($data['is_active'] ?? true);
            $this->created_at = $data['created_at'] ?? null;
            $this->updated_at = $data['updated_at'] ?? null;
        } else {
            $this->id = null;
            $this->nombre = null;
            $this->email = null;
            $this->is_active = true;
            $this->created_at = null;
            $this->updated_at = null;
        }
    }

    public function get($id)
    {
        $data = $this->db->select(
            "SELECT * FROM " . $this->table_name
            . " WHERE id = " . $this->var2str($id) . ";"
        );
        return $data ? new self($data[0]) : false;
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }
        return (bool) $this->db->select(
            "SELECT id FROM " . $this->table_name
            . " WHERE id = " . $this->var2str($this->id) . ";"
        );
    }

    public function test(): bool
    {
        $this->nombre = $this->no_html($this->nombre);

        if (empty($this->nombre)) {
            $this->new_error_msg('El nombre es obligatorio');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        $this->updated_at = date('Y-m-d H:i:s');

        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET "
                . "nombre = " . $this->var2str($this->nombre)
                . ", email = " . $this->var2str($this->email)
                . ", is_active = " . $this->var2str($this->is_active)
                . ", updated_at = " . $this->var2str($this->updated_at)
                . " WHERE id = " . $this->var2str($this->id) . ";";
        } else {
            $this->created_at = date('Y-m-d H:i:s');
            $sql = "INSERT INTO " . $this->table_name
                . " (nombre, email, is_active, created_at, updated_at) VALUES ("
                . $this->var2str($this->nombre) . ","
                . $this->var2str($this->email) . ","
                . $this->var2str($this->is_active) . ","
                . $this->var2str($this->created_at) . ","
                . $this->var2str($this->updated_at) . ");";
        }

        if ($this->db->exec($sql)) {
            if (is_null($this->id)) {
                $this->id = $this->db->lastval();
            }
            return true;
        }
        return false;
    }

    public function delete()
    {
        return $this->db->exec(
            "DELETE FROM " . $this->table_name
            . " WHERE id = " . $this->var2str($this->id) . ";"
        );
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select(
            "SELECT * FROM " . $this->table_name . " ORDER BY nombre;"
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new self($d);
            }
        }
        return $list;
    }

    protected function install()
    {
        return '';
    }
}
```

## Step 3: Add Symfony Validation (modern models)

For models in `Model/` (PSR-4), add `ValidatorTrait` and attributes:

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\mi_plugin\Model;

use FSFramework\Traits\ValidatorTrait;
use Symfony\Component\Validator\Constraints as Assert;

class MiModelo extends \fs_model
{
    use ValidatorTrait;

    #[Assert\NotBlank(message: 'El nombre es obligatorio')]
    #[Assert\Length(max: 150)]
    public string $nombre = '';

    #[Assert\Email(message: 'Email inválido')]
    public string $email = '';

    public function test(): bool
    {
        $this->nombre = $this->no_html($this->nombre);
        return $this->validate();
    }
}
```

## Step 4: Write Tests

```php
<?php

declare(strict_types=1);

namespace Tests\MiPlugin;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/MiPlugin/model/mi_modelo.php';

class MiModeloTest extends TestCase
{
    private function createModel(array $data = []): \mi_modelo
    {
        return new class($data) extends \mi_modelo {
            public function __construct($data = false)
            {
                // Skip parent to avoid DB — set table_name manually
                if ($data) {
                    $this->id = $data['id'] ?? null;
                    $this->nombre = $data['nombre'] ?? null;
                    $this->email = $data['email'] ?? null;
                    $this->is_active = true;
                }
            }
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return $this->test(); }
        };
    }

    public function testValidModelPasses(): void
    {
        $model = $this->createModel(['nombre' => 'Test']);
        $this->assertTrue($model->test());
    }

    public function testEmptyNameFails(): void
    {
        $model = $this->createModel(['nombre' => '']);
        $this->assertFalse($model->test());
    }

    public function testHtmlIsSanitized(): void
    {
        $model = $this->createModel(['nombre' => '<script>alert(1)</script>']);
        $model->test();
        $this->assertStringNotContainsString('<script>', $model->nombre);
    }
}
```

## Security Checklist for Models

- All SQL uses `$this->var2str()` — never string concatenation
- `test()` sanitizes with `$this->no_html()` before persisting
- Boolean fields use `$this->str2bool()` in constructor
- Nullable fields check `$data['field'] ?? null` in constructor
- `save()` always calls `$this->test()` first
