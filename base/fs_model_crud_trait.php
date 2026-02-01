<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Trait con operaciones CRUD genéricas para modelos
 * 
 * Reduce la duplicación de código en modelos que extienden fs_model.
 * Proporciona implementaciones genéricas de exists(), delete(), get(), 
 * save(), all() y clear() basadas en metadatos del modelo.
 * 
 * Uso:
 *   class mi_modelo extends fs_model {
 *       use fs_model_crud_trait;
 *       
 *       protected static string $primaryKey = 'codmodelo';
 *       protected static array $fields = ['codmodelo', 'nombre', 'activo'];
 *       protected static array $defaults = ['activo' => true];
 *       
 *       // Solo implementar test() con validaciones específicas
 *       public function test() { ... }
 *   }
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
trait fs_model_crud_trait
{
    /**
     * Cache de campos de la tabla
     * @var array
     */
    private static array $fieldCache = [];

    /**
     * Obtiene el nombre de la columna primaria.
     * Sobrescribir en el modelo si es diferente.
     * 
     * @return string
     */
    public function primaryColumn(): string
    {
        return static::$primaryKey ?? 'id';
    }

    /**
     * Obtiene el valor de la clave primaria.
     * 
     * @return mixed
     */
    public function primaryValue()
    {
        $pk = $this->primaryColumn();
        return $this->{$pk} ?? null;
    }

    /**
     * Obtiene el nombre de la clase del modelo.
     * 
     * @return string
     */
    public function modelClassName(): string
    {
        return static::class;
    }

    /**
     * Obtiene los campos del modelo.
     * Usa la propiedad estática $fields si existe, sino lee de la BD.
     * 
     * @return array
     */
    public function getFields(): array
    {
        $tableName = $this->table_name;
        
        if (isset(self::$fieldCache[$tableName])) {
            return self::$fieldCache[$tableName];
        }

        // Si hay campos definidos estáticamente, usarlos
        if (isset(static::$fields) && !empty(static::$fields)) {
            self::$fieldCache[$tableName] = static::$fields;
            return static::$fields;
        }

        // Sino, leer de la base de datos
        $fields = [];
        foreach ($this->db->get_columns($tableName) as $column) {
            $fields[] = $column['name'];
        }
        
        self::$fieldCache[$tableName] = $fields;
        return $fields;
    }

    /**
     * Limpia/resetea todas las propiedades del modelo.
     * 
     * @return void
     */
    public function clear(): void
    {
        $defaults = static::$defaults ?? [];
        
        foreach ($this->getFields() as $field) {
            $this->{$field} = $defaults[$field] ?? null;
        }
    }

    /**
     * Carga datos desde un array.
     * 
     * @param array $data Datos a cargar
     * @return void
     */
    public function loadFromData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Verifica si el registro existe en la base de datos.
     * 
     * @return bool
     */
    public function exists(): bool
    {
        $pk = $this->primaryColumn();
        $value = $this->primaryValue();
        
        if ($value === null) {
            return false;
        }

        $sql = "SELECT 1 FROM " . $this->table_name . 
               " WHERE " . $pk . " = " . $this->var2str($value) . " LIMIT 1";
        
        return (bool) $this->db->select($sql);
    }

    /**
     * Elimina el registro de la base de datos.
     * 
     * @return bool
     */
    public function delete(): bool
    {
        $pk = $this->primaryColumn();
        $value = $this->primaryValue();
        
        if ($value === null) {
            return false;
        }

        $this->clean_cache();
        
        $sql = "DELETE FROM " . $this->table_name . 
               " WHERE " . $pk . " = " . $this->var2str($value);
        
        return (bool) $this->db->exec($sql);
    }

    /**
     * Obtiene un registro por su clave primaria.
     * 
     * @param mixed $code Valor de la clave primaria
     * @param string $column Columna alternativa (opcional)
     * @return static|false
     */
    public function get($code, string $column = '')
    {
        $col = $column ?: $this->primaryColumn();
        
        $sql = "SELECT * FROM " . $this->table_name . 
               " WHERE " . $col . " = " . $this->var2str($code);
        
        $data = $this->db->select($sql);
        
        if ($data) {
            $className = $this->modelClassName();
            return new $className($data[0]);
        }
        
        return false;
    }

    /**
     * Guarda el registro (INSERT o UPDATE).
     * 
     * @return bool
     */
    public function save(): bool
    {
        // Ejecutar test() si existe
        if (method_exists($this, 'test') && !$this->test()) {
            return false;
        }

        $this->clean_cache();

        if ($this->exists()) {
            return $this->saveUpdate();
        }
        
        return $this->saveInsert();
    }

    /**
     * Ejecuta un INSERT.
     * 
     * @return bool
     */
    protected function saveInsert(): bool
    {
        $pk = $this->primaryColumn();
        $columns = [];
        $values = [];

        foreach ($this->getFields() as $field) {
            $value = $this->{$field} ?? null;
            
            // Omitir PK si es null (auto-increment)
            if ($field === $pk && $value === null) {
                continue;
            }
            
            $columns[] = $field;
            $values[] = $this->var2str($value);
        }

        $sql = "INSERT INTO " . $this->table_name . 
               " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";

        if ($this->db->exec($sql)) {
            // Obtener ID auto-generado si aplica
            if ($this->primaryValue() === null) {
                $this->{$pk} = $this->db->lastval();
            }
            return true;
        }

        return false;
    }

    /**
     * Ejecuta un UPDATE.
     * 
     * @return bool
     */
    protected function saveUpdate(): bool
    {
        $pk = $this->primaryColumn();
        $sets = [];

        foreach ($this->getFields() as $field) {
            if ($field === $pk) {
                continue;
            }
            $sets[] = $field . " = " . $this->var2str($this->{$field} ?? null);
        }

        $sql = "UPDATE " . $this->table_name . 
               " SET " . implode(', ', $sets) . 
               " WHERE " . $pk . " = " . $this->var2str($this->primaryValue());

        return (bool) $this->db->exec($sql);
    }

    /**
     * Obtiene todos los registros.
     * 
     * @param string $orderBy Columna para ordenar
     * @param string $order Dirección (ASC/DESC)
     * @return array
     */
    public function all(string $orderBy = '', string $order = 'ASC'): array
    {
        $sql = "SELECT * FROM " . $this->table_name;
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy . " " . $order;
        }

        $data = $this->db->select($sql);
        $result = [];
        
        if ($data) {
            $className = $this->modelClassName();
            foreach ($data as $row) {
                $result[] = new $className($row);
            }
        }

        return $result;
    }

    /**
     * Obtiene registros con paginación.
     * 
     * @param int $offset Inicio
     * @param int $limit Cantidad
     * @param string $orderBy Columna para ordenar
     * @param string $order Dirección
     * @return array
     */
    public function allPaginated(int $offset = 0, int $limit = FS_ITEM_LIMIT, string $orderBy = '', string $order = 'ASC'): array
    {
        $sql = "SELECT * FROM " . $this->table_name;
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy . " " . $order;
        }

        $data = $this->db->select_limit($sql, $limit, $offset);
        $result = [];
        
        if ($data) {
            $className = $this->modelClassName();
            foreach ($data as $row) {
                $result[] = new $className($row);
            }
        }

        return $result;
    }

    /**
     * Cuenta el total de registros.
     * 
     * @return int
     */
    public function count(): int
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name;
        $data = $this->db->select($sql);
        
        return $data ? (int) $data[0]['total'] : 0;
    }

    /**
     * Busca registros por una condición.
     * 
     * @param string $column Columna
     * @param mixed $value Valor
     * @param string $operator Operador (=, LIKE, etc.)
     * @return array
     */
    public function findBy(string $column, $value, string $operator = '='): array
    {
        $sql = "SELECT * FROM " . $this->table_name . 
               " WHERE " . $column . " " . $operator . " " . $this->var2str($value);

        $data = $this->db->select($sql);
        $result = [];
        
        if ($data) {
            $className = $this->modelClassName();
            foreach ($data as $row) {
                $result[] = new $className($row);
            }
        }

        return $result;
    }

    /**
     * Busca un único registro por una condición.
     * 
     * @param string $column Columna
     * @param mixed $value Valor
     * @return static|false
     */
    public function findOneBy(string $column, $value)
    {
        $sql = "SELECT * FROM " . $this->table_name . 
               " WHERE " . $column . " = " . $this->var2str($value) . " LIMIT 1";

        $data = $this->db->select($sql);
        
        if ($data) {
            $className = $this->modelClassName();
            return new $className($data[0]);
        }

        return false;
    }

    /**
     * Sanitiza todos los campos de texto.
     * Útil para llamar al inicio de test().
     * 
     * @param array $fields Campos a sanitizar (vacío = todos los string)
     * @return void
     */
    protected function sanitizeFields(array $fields = []): void
    {
        if (empty($fields)) {
            $fields = $this->getFields();
        }

        foreach ($fields as $field) {
            if (isset($this->{$field}) && is_string($this->{$field})) {
                $this->{$field} = $this->no_html($this->{$field});
            }
        }
    }

    /**
     * Convierte el modelo a array.
     * 
     * @return array
     */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->getFields() as $field) {
            $data[$field] = $this->{$field} ?? null;
        }
        return $data;
    }

    /**
     * Crea una instancia desde un array.
     * 
     * @param array $data Datos
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
