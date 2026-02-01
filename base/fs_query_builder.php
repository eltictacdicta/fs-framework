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
 * Constructor de consultas SQL fluido
 * 
 * Proporciona una interfaz fluida para construir consultas SQL
 * de forma segura y legible. Compatible con fs_db2.
 * 
 * Uso:
 *   $qb = new fs_query_builder();
 *   $clientes = $qb->table('clientes')
 *       ->where('activo', true)
 *       ->where('codagente', '=', $agente)
 *       ->orderBy('nombre')
 *       ->get();
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_query_builder
{
    /**
     * @var string
     */
    private $table = '';
    
    /**
     * @var array
     */
    private $selectColumns = ['*'];
    
    /**
     * @var array
     */
    private $whereConditions = [];
    
    /**
     * @var array
     */
    private $joins = [];
    
    /**
     * @var array
     */
    private $orderByColumns = [];
    
    /**
     * @var array
     */
    private $groupByColumns = [];
    
    /**
     * @var int|null
     */
    private $limitValue = null;
    
    /**
     * @var int|null
     */
    private $offsetValue = null;
    
    /**
     * @var string
     */
    private $sqlType = 'SELECT';
    
    /**
     * @var array
     */
    private $data = [];
    
    /**
     * @var fs_db2|null
     */
    private $db = null;

    /**
     * Constructor
     * 
     * @param fs_db2|null $db Instancia de base de datos (opcional)
     */
    public function __construct($db = null)
    {
        $this->db = $db;
    }

    /**
     * Obtiene o crea la instancia de base de datos
     * 
     * @return fs_db2
     */
    private function getDb()
    {
        if ($this->db === null) {
            $this->db = new fs_db2();
        }
        return $this->db;
    }

    /**
     * Establece la tabla
     * 
     * @param string $table Nombre de la tabla
     * @return $this
     */
    public function table($table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Alias de table()
     * 
     * @param string $table Nombre de la tabla
     * @return $this
     */
    public function from($table)
    {
        return $this->table($table);
    }

    /**
     * Establece columnas para SELECT
     * 
     * @param array|string $columns Columnas a seleccionar
     * @return $this
     */
    public function select($columns)
    {
        $this->sqlType = 'SELECT';
        $this->selectColumns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    /**
     * Agrega columnas al SELECT existente
     * 
     * @param array|string $columns Columnas adicionales
     * @return $this
     */
    public function addSelect($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        
        if ($this->selectColumns === ['*']) {
            $this->selectColumns = $columns;
        } else {
            $this->selectColumns = array_merge($this->selectColumns, $columns);
        }
        
        return $this;
    }

    /**
     * SELECT DISTINCT
     * 
     * @param array|string $columns Columnas
     * @return $this
     */
    public function distinct($columns = null)
    {
        if ($columns !== null) {
            $this->selectColumns = is_array($columns) ? $columns : func_get_args();
        }
        $this->selectColumns[0] = 'DISTINCT ' . $this->selectColumns[0];
        return $this;
    }

    /**
     * Agrega condición WHERE
     * 
     * @param string $column Columna
     * @param string $operator Operador o valor
     * @param mixed $value Valor (opcional)
     * @return $this
     */
    public function where($column, $operator = '=', $value = null)
    {
        // Si solo se pasan 2 argumentos, el segundo es el valor
        if ($value === null && $operator !== '=' && !in_array(strtoupper($operator), ['IS NULL', 'IS NOT NULL', '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'])) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->whereConditions[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * Agrega condición OR WHERE
     * 
     * @param string $column Columna
     * @param string $operator Operador o valor
     * @param mixed $value Valor (opcional)
     * @return $this
     */
    public function orWhere($column, $operator = '=', $value = null)
    {
        if ($value === null && $operator !== '=' && !in_array(strtoupper($operator), ['IS NULL', 'IS NOT NULL', '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'])) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->whereConditions[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * WHERE columna IS NULL
     * 
     * @param string $column Columna
     * @return $this
     */
    public function whereNull($column)
    {
        return $this->where($column, 'IS NULL', null);
    }

    /**
     * WHERE columna IS NOT NULL
     * 
     * @param string $column Columna
     * @return $this
     */
    public function whereNotNull($column)
    {
        return $this->where($column, 'IS NOT NULL', null);
    }

    /**
     * WHERE columna IN (valores)
     * 
     * @param string $column Columna
     * @param array $values Valores
     * @return $this
     */
    public function whereIn($column, $values)
    {
        return $this->where($column, 'IN', $values);
    }

    /**
     * WHERE columna NOT IN (valores)
     * 
     * @param string $column Columna
     * @param array $values Valores
     * @return $this
     */
    public function whereNotIn($column, $values)
    {
        return $this->where($column, 'NOT IN', $values);
    }

    /**
     * WHERE columna BETWEEN valor1 AND valor2
     * 
     * @param string $column Columna
     * @param mixed $value1 Valor mínimo
     * @param mixed $value2 Valor máximo
     * @return $this
     */
    public function whereBetween($column, $value1, $value2)
    {
        return $this->where($column, 'BETWEEN', [$value1, $value2]);
    }

    /**
     * WHERE columna LIKE valor
     * 
     * @param string $column Columna
     * @param string $value Patrón
     * @return $this
     */
    public function whereLike($column, $value)
    {
        return $this->where($column, 'LIKE', $value);
    }

    /**
     * WHERE con SQL raw
     * 
     * @param string $sql SQL crudo
     * @return $this
     */
    public function whereRaw($sql)
    {
        $this->whereConditions[] = [
            'type' => 'AND',
            'raw' => $sql
        ];
        return $this;
    }

    /**
     * Agrega JOIN
     * 
     * @param string $table Tabla a unir
     * @param string $first Primera columna
     * @param string $operator Operador
     * @param string $second Segunda columna
     * @param string $type Tipo de JOIN (INNER, LEFT, RIGHT)
     * @return $this
     */
    public function join($table, $first, $operator, $second, $type = 'INNER')
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        
        return $this;
    }

    /**
     * Agrega LEFT JOIN
     * 
     * @param string $table Tabla
     * @param string $first Primera columna
     * @param string $operator Operador
     * @param string $second Segunda columna
     * @return $this
     */
    public function leftJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Agrega RIGHT JOIN
     * 
     * @param string $table Tabla
     * @param string $first Primera columna
     * @param string $operator Operador
     * @param string $second Segunda columna
     * @return $this
     */
    public function rightJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * Establece ORDER BY
     * 
     * @param string $column Columna
     * @param string $direction Dirección (ASC, DESC)
     * @return $this
     */
    public function orderBy($column, $direction = 'ASC')
    {
        $this->orderByColumns[] = [$column, strtoupper($direction)];
        return $this;
    }

    /**
     * ORDER BY DESC
     * 
     * @param string $column Columna
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * ORDER BY más reciente (para columnas de fecha)
     * 
     * @param string $column Columna de fecha
     * @return $this
     */
    public function latest($column = 'fecha')
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * ORDER BY más antiguo (para columnas de fecha)
     * 
     * @param string $column Columna de fecha
     * @return $this
     */
    public function oldest($column = 'fecha')
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Establece GROUP BY
     * 
     * @param array|string $columns Columnas
     * @return $this
     */
    public function groupBy($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->groupByColumns = array_merge($this->groupByColumns, $columns);
        return $this;
    }

    /**
     * Establece LIMIT
     * 
     * @param int $limit Límite
     * @param int|null $offset Offset (opcional)
     * @return $this
     */
    public function limit($limit, $offset = null)
    {
        $this->limitValue = (int) $limit;
        if ($offset !== null) {
            $this->offsetValue = (int) $offset;
        }
        return $this;
    }

    /**
     * Alias de limit()
     * 
     * @param int $value Límite
     * @return $this
     */
    public function take($value)
    {
        return $this->limit($value);
    }

    /**
     * Establece OFFSET
     * 
     * @param int $offset Offset
     * @return $this
     */
    public function offset($offset)
    {
        $this->offsetValue = (int) $offset;
        return $this;
    }

    /**
     * Alias de offset()
     * 
     * @param int $value Offset
     * @return $this
     */
    public function skip($value)
    {
        return $this->offset($value);
    }

    /**
     * Prepara una consulta INSERT
     * 
     * @param array $data Datos a insertar
     * @return $this
     */
    public function insert($data)
    {
        $this->sqlType = 'INSERT';
        $this->data = $data;
        return $this;
    }

    /**
     * Prepara una consulta UPDATE
     * 
     * @param array $data Datos a actualizar
     * @return $this
     */
    public function update($data)
    {
        $this->sqlType = 'UPDATE';
        $this->data = $data;
        return $this;
    }

    /**
     * Prepara una consulta DELETE
     * 
     * @return $this
     */
    public function delete()
    {
        $this->sqlType = 'DELETE';
        return $this;
    }

    /**
     * Construye la consulta SQL
     * 
     * @return string
     */
    public function toSql()
    {
        switch ($this->sqlType) {
            case 'SELECT':
                return $this->buildSelect();
            case 'INSERT':
                return $this->buildInsert();
            case 'UPDATE':
                return $this->buildUpdate();
            case 'DELETE':
                return $this->buildDelete();
            default:
                throw new InvalidArgumentException('Tipo de consulta no válido');
        }
    }

    /**
     * Ejecuta la consulta y devuelve el resultado
     * 
     * @return array|bool
     */
    public function execute()
    {
        $sql = $this->toSql();
        $db = $this->getDb();
        
        if ($this->sqlType === 'SELECT') {
            if ($this->limitValue !== null) {
                return $db->select_limit($sql, $this->limitValue, $this->offsetValue ?: 0);
            }
            return $db->select($sql);
        }
        
        return $db->exec($sql);
    }

    /**
     * Obtiene todos los resultados como array
     * 
     * @return array
     */
    public function get()
    {
        $result = $this->execute();
        return is_array($result) ? $result : [];
    }

    /**
     * Obtiene el primer resultado
     * 
     * @return array|null
     */
    public function first()
    {
        $this->limitValue = 1;
        $result = $this->get();
        return isset($result[0]) ? $result[0] : null;
    }

    /**
     * Obtiene un valor de una columna específica
     * 
     * @param string $column Columna
     * @return mixed|null
     */
    public function value($column)
    {
        $this->selectColumns = [$column];
        $row = $this->first();
        return $row !== null && isset($row[$column]) ? $row[$column] : null;
    }

    /**
     * Obtiene todos los valores de una columna
     * 
     * @param string $column Columna
     * @return array
     */
    public function pluck($column)
    {
        $this->selectColumns = [$column];
        $results = $this->get();
        
        $values = [];
        foreach ($results as $row) {
            if (isset($row[$column])) {
                $values[] = $row[$column];
            }
        }
        
        return $values;
    }

    /**
     * Cuenta el número de registros
     * 
     * @return int
     */
    public function count()
    {
        $this->selectColumns = ['COUNT(*) as count'];
        $result = $this->first();
        return $result !== null ? (int) $result['count'] : 0;
    }

    /**
     * Obtiene el valor máximo de una columna
     * 
     * @param string $column Columna
     * @return mixed|null
     */
    public function max($column)
    {
        $this->selectColumns = ["MAX({$column}) as max_value"];
        $result = $this->first();
        return $result !== null ? $result['max_value'] : null;
    }

    /**
     * Obtiene el valor mínimo de una columna
     * 
     * @param string $column Columna
     * @return mixed|null
     */
    public function min($column)
    {
        $this->selectColumns = ["MIN({$column}) as min_value"];
        $result = $this->first();
        return $result !== null ? $result['min_value'] : null;
    }

    /**
     * Obtiene la suma de una columna
     * 
     * @param string $column Columna
     * @return float
     */
    public function sum($column)
    {
        $this->selectColumns = ["SUM({$column}) as sum_value"];
        $result = $this->first();
        return $result !== null ? (float) $result['sum_value'] : 0.0;
    }

    /**
     * Obtiene el promedio de una columna
     * 
     * @param string $column Columna
     * @return float
     */
    public function avg($column)
    {
        $this->selectColumns = ["AVG({$column}) as avg_value"];
        $result = $this->first();
        return $result !== null ? (float) $result['avg_value'] : 0.0;
    }

    /**
     * Verifica si existe al menos un registro
     * 
     * @return bool
     */
    public function exists()
    {
        return $this->count() > 0;
    }

    /**
     * Verifica si no existe ningún registro
     * 
     * @return bool
     */
    public function doesntExist()
    {
        return !$this->exists();
    }

    /**
     * Construye consulta SELECT
     * 
     * @return string
     */
    private function buildSelect()
    {
        $sql = 'SELECT ' . implode(', ', $this->selectColumns);
        $sql .= ' FROM ' . $this->table;
        
        // JOINs
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }
        
        // WHERE
        $sql .= $this->buildWhere();
        
        // GROUP BY
        if (!empty($this->groupByColumns)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupByColumns);
        }
        
        // ORDER BY
        if (!empty($this->orderByColumns)) {
            $orders = [];
            foreach ($this->orderByColumns as $order) {
                $orders[] = "{$order[0]} {$order[1]}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }
        
        return $sql;
    }

    /**
     * Construye consulta INSERT
     * 
     * @return string
     */
    private function buildInsert()
    {
        $columns = array_keys($this->data);
        $values = [];
        
        foreach ($this->data as $value) {
            $values[] = $this->escapeValue($value);
        }
        
        return 'INSERT INTO ' . $this->table . 
               ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    }

    /**
     * Construye consulta UPDATE
     * 
     * @return string
     */
    private function buildUpdate()
    {
        $sets = [];
        foreach ($this->data as $column => $value) {
            $sets[] = "{$column} = " . $this->escapeValue($value);
        }
        
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets);
        $sql .= $this->buildWhere();
        
        return $sql;
    }

    /**
     * Construye consulta DELETE
     * 
     * @return string
     */
    private function buildDelete()
    {
        $sql = 'DELETE FROM ' . $this->table;
        $sql .= $this->buildWhere();
        
        return $sql;
    }

    /**
     * Construye cláusula WHERE
     * 
     * @return string
     */
    private function buildWhere()
    {
        if (empty($this->whereConditions)) {
            return '';
        }
        
        $conditions = [];
        foreach ($this->whereConditions as $i => $where) {
            $prefix = $i === 0 ? ' WHERE ' : " {$where['type']} ";
            
            // SQL raw
            if (isset($where['raw'])) {
                $conditions[] = $prefix . $where['raw'];
                continue;
            }
            
            $column = $where['column'];
            $operator = strtoupper($where['operator']);
            $value = $where['value'];
            
            // Operadores especiales
            if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                $conditions[] = $prefix . $column . ' ' . $operator;
            } elseif ($operator === 'IN' || $operator === 'NOT IN') {
                $escapedValues = [];
                foreach ((array) $value as $v) {
                    $escapedValues[] = $this->escapeValue($v);
                }
                $conditions[] = $prefix . $column . ' ' . $operator . ' (' . implode(', ', $escapedValues) . ')';
            } elseif ($operator === 'BETWEEN') {
                $conditions[] = $prefix . $column . ' BETWEEN ' . $this->escapeValue($value[0]) . ' AND ' . $this->escapeValue($value[1]);
            } else {
                $conditions[] = $prefix . $column . ' ' . $where['operator'] . ' ' . $this->escapeValue($value);
            }
        }
        
        return implode('', $conditions);
    }

    /**
     * Escapa un valor para SQL
     * 
     * @param mixed $value Valor a escapar
     * @return string
     */
    private function escapeValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        
        $db = $this->getDb();
        return "'" . $db->escape_string((string) $value) . "'";
    }

    /**
     * Reinicia el builder
     * 
     * @return $this
     */
    public function reset()
    {
        $this->table = '';
        $this->selectColumns = ['*'];
        $this->whereConditions = [];
        $this->joins = [];
        $this->orderByColumns = [];
        $this->groupByColumns = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->sqlType = 'SELECT';
        $this->data = [];
        
        return $this;
    }

    /**
     * Clona el builder actual
     * 
     * @return fs_query_builder
     */
    public function clone()
    {
        return clone $this;
    }

    /**
     * Método mágico para depuración
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->toSql();
    }
}

/**
 * Función helper para crear un query builder
 * 
 * @param string|null $table Nombre de la tabla (opcional)
 * @return fs_query_builder
 */
function db($table = null)
{
    $qb = new fs_query_builder();
    if ($table !== null) {
        $qb->table($table);
    }
    return $qb;
}
