<?php
/**
 * EJEMPLO: Modelo almacen refactorizado usando fs_model_crud_trait
 * 
 * Este archivo muestra cómo se vería el modelo almacen.php
 * después de migrar al trait CRUD. 
 * 
 * ANTES: 297 líneas
 * DESPUÉS: ~80 líneas (reducción del 73%)
 * 
 * NO USAR EN PRODUCCIÓN - Solo ejemplo de referencia
 */

require_once 'base/fs_model.php';
require_once 'base/fs_model_crud_trait.php';

/**
 * El almacén donde están físicamente los artículos.
 * Versión refactorizada usando fs_model_crud_trait.
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 * @author Javier Trujillo <mistertekcom@gmail.com> (refactorización)
 */
class almacen_refactored extends fs_model
{
    use fs_model_crud_trait;

    // =========================================================================
    // METADATOS DEL MODELO (reemplazan código repetitivo)
    // =========================================================================
    
    protected static string $primaryKey = 'codalmacen';
    
    protected static array $fields = [
        'codalmacen', 'nombre', 'direccion', 'codpostal', 'poblacion',
        'provincia', 'codpais', 'apartado', 'telefono', 'fax',
        'contacto', 'observaciones', 'idprovincia', 'porpvp', 'tipovaloracion'
    ];
    
    protected static array $defaults = [
        'direccion' => '',
        'codpostal' => '',
        'poblacion' => '',
        'provincia' => '',
        'codpais' => '',
        'apartado' => '',
        'telefono' => '',
        'fax' => '',
        'contacto' => '',
        'observaciones' => '',
        'idprovincia' => null,
        'porpvp' => 0,
        'tipovaloracion' => '',
    ];

    // =========================================================================
    // PROPIEDADES (igual que antes)
    // =========================================================================
    
    public $codalmacen;
    public $nombre;
    public $direccion;
    public $codpostal;
    public $poblacion;
    public $provincia;
    public $codpais;
    public $apartado;
    public $telefono;
    public $fax;
    public $contacto;
    public $observaciones;
    public $idprovincia;
    public $porpvp;
    public $tipovaloracion;

    // =========================================================================
    // CONSTRUCTOR (simplificado)
    // =========================================================================

    public function __construct($data = false)
    {
        parent::__construct('almacenes');
        
        if ($data) {
            $this->loadFromData($data);
            // Conversión de tipos específica
            $this->porpvp = floatval($this->porpvp ?? 0);
        } else {
            $this->clear();
        }
    }

    // =========================================================================
    // MÉTODOS ESPECÍFICOS (solo lo que NO está en el trait)
    // =========================================================================

    protected function install()
    {
        return "INSERT INTO " . $this->table_name . " (codalmacen,nombre,poblacion,"
            . "direccion,codpostal,telefono,fax,contacto) VALUES "
            . "('ALG','ALMACEN GENERAL','','','','','','');";
    }

    public function url()
    {
        if (is_null($this->codalmacen)) {
            return "index.php?page=admin_almacenes";
        }
        return "index.php?page=admin_almacenes#" . $this->codalmacen;
    }

    /**
     * Devuelve TRUE si este es almacén predeterminado de la empresa.
     */
    public function is_default(): bool
    {
        return $this->codalmacen == $this->default_items->codalmacen();
    }

    /**
     * Validación específica del modelo.
     * El trait llama a este método automáticamente en save().
     */
    public function test(): bool
    {
        // Sanitizar campos de texto (método del trait)
        $this->sanitizeFields([
            'nombre', 'direccion', 'codpostal', 'poblacion', 'provincia',
            'apartado', 'telefono', 'fax', 'contacto', 'observaciones'
        ]);

        // Validación específica
        if (!preg_match("/^[A-Z0-9]{1,4}$/i", $this->codalmacen)) {
            $this->new_error_msg("Código de almacén no válido.");
            return false;
        }

        return true;
    }

    // =========================================================================
    // MÉTODOS ELIMINADOS (ahora los proporciona el trait):
    // - get($cod)        → trait::get()
    // - exists()         → trait::exists()
    // - save()           → trait::save()
    // - delete()         → trait::delete()
    // - all()            → trait::all()
    // - clear()          → trait::clear()
    // =========================================================================
}

// =============================================================================
// COMPARACIÓN DE USO
// =============================================================================

/*
// El uso es IDÉNTICO al modelo original:

$almacen = new almacen_refactored();
$almacen->codalmacen = 'ALM1';
$almacen->nombre = 'Almacén Principal';
$almacen->save();

// Obtener por código
$alm = $almacen->get('ALM1');

// Listar todos
$almacenes = $almacen->all();

// NUEVOS métodos disponibles gracias al trait:

// Buscar por campo
$almacenes = $almacen->findBy('provincia', 'Madrid');

// Contar
$total = $almacen->count();

// Paginación
$pagina1 = $almacen->allPaginated(0, 10, 'nombre', 'ASC');

// Convertir a array
$data = $almacen->toArray();

// Crear desde array
$nuevo = almacen_refactored::fromArray(['codalmacen' => 'NEW', 'nombre' => 'Nuevo']);
*/
