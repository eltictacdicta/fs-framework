<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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
require_once 'base/fs_app.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_default_items.php';
require_once 'base/fs_extended_model.php';
require_once 'base/fs_login.php';

// fs_divisa_tools has been moved to plugins/business_data/extras/
// Load it only if business_data plugin is ACTIVE (not just exists)
if (in_array('business_data', $GLOBALS['plugins'] ?? []) 
    && file_exists(FS_FOLDER . '/plugins/business_data/extras/fs_divisa_tools.php')) {
    require_once FS_FOLDER . '/plugins/business_data/extras/fs_divisa_tools.php';
}

// OPTIMIZACIÓN: Usar autoloader de modelos en lugar de cargar todos
// Esto reduce el tiempo de carga de ~7ms a ~0.5ms y memoria de ~856KB a ~10KB
// Los modelos se cargan bajo demanda cuando se instancian
if (defined('FS_LAZY_MODELS') && FS_LAZY_MODELS) {
    require_once 'base/fs_model_autoloader.php';
    fs_model_autoloader::register();
} else {
    // Modo legacy: cargar todos los modelos al inicio
    require_all_models();
}

/**
 * La clase principal de la que deben heredar todos los controladores
 * (las páginas) de FSFramework.
 * 
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
#[AllowDynamicProperties]
class fs_controller extends fs_app
{
    use \FSFramework\Traits\ResponseTrait;

    /**
     * Objeto Request de Symfony HttpFoundation para acceso a parámetros
     * de la petición de forma moderna y segura.
     * @var \Symfony\Component\HttpFoundation\Request
     */
    protected $request;

    /**
     * Indica si la validación CSRF pasó para peticiones POST.
     * @var bool
     */
    protected $csrf_valid = true;

    /**
     * Indica si es una petición AJAX.
     * @var bool
     */
    protected $is_ajax = false;

    /**
     * Nombre del controlador (lo utilizamos en lugar de __CLASS__ porque __CLASS__
     * en las funciones de la clase padre es el nombre de la clase padre).
     * @var string 
     */
    protected $class_name;

    /**
     * Este objeto permite acceso directo a la base de datos.
     * @var fs_db2
     */
    protected $db;

    /**
     * Currency tools instance (provided by business_data plugin)
     * @var fs_divisa_tools|null
     */
    protected $divisa_tools = null;

    /**
     * Permite consultar los parámetros predeterminados para series, divisas, forma de pago, etc...
     * @var fs_default_items 
     */
    public $default_items;

    /**
     * La empresa
     * @var empresa
     */
    public $empresa;

    /**
     * Listado de extensiones de la página
     * @var array 
     */
    public $extensions;

    /**
     * Indica si FacturaScripts está actualizado o no.
     * @var boolean 
     */
    private $fs_updated;

    /**
     * Listado con los últimos cambios en documentos.
     * @var array 
     */
    private $last_changes;

    /**
     *
     * @var fs_login
     */
    private $login_tools;

    /**
     * Contiene el menú de FSFramework
     * @var array
     */
    protected $menu;

    /**
     * El elemento del menú de esta página
     * @var fs_page
     */
    public $page;

    /**
     * Esta variable contiene el texto enviado como parámetro query por cualquier formulario,
     * es decir, se corresponde con $_REQUEST['query']
     * @var string|boolean
     */
    public $query;

    /**
     * Indica que archivo HTML hay que cargar
     * @var string|false 
     */
    public $template;

    /**
     * El usuario que ha hecho login
     * @var fs_user
     */
    public $user;

    /**
     * @param string $name sustituir por __CLASS__
     * @param string $title es el título de la página, y el texto que aparecerá en el menú
     * @param string $folder es el menú dónde quieres colocar el acceso directo
     * @param boolean $admin OBSOLETO
     * @param boolean $shmenu debe ser TRUE si quieres añadir el acceso directo en el menú
     * @param boolean $important debe ser TRUE si quieres que aparezca en el menú de destacado
     */
    public function __construct($name = __CLASS__, $title = 'home', $folder = '', $admin = FALSE, $shmenu = TRUE, $important = FALSE)
    {
        parent::__construct($name);
        $this->class_name = $name;
        $this->request = \FSFramework\Core\Kernel::request();
        $this->db = new fs_db2();
        $this->extensions = [];
        
        // Detect AJAX requests
        $this->is_ajax = $this->request->isXmlHttpRequest() 
            || $this->request->query->has('ajax') 
            || $this->request->request->has('ajax');

        if ($this->db->connect()) {
            $this->user = new fs_user();
            $this->check_fs_page($name, $title, $folder, $shmenu, $important);

            $this->default_items = new fs_default_items();
            $this->login_tools = new fs_login();

            $this->load_extensions();

            if (class_exists('empresa')) {
                $this->empresa = new empresa();
                $empresa_data = $this->empresa->get();
                if ($empresa_data) {
                    $this->empresa = $empresa_data;
                }
            }

            /// Inicializamos las herramientas de divisa con la divisa de la empresa
            /// (solo si business_data plugin está disponible)
            if (class_exists('fs_divisa_tools')) {
                $coddivisa = ($this->empresa && isset($this->empresa->coddivisa) && $this->empresa->coddivisa)
                    ? $this->empresa->coddivisa
                    : 'EUR';
                $this->divisa_tools = new fs_divisa_tools($coddivisa);
            }

            if ($this->request->query->has('logout')) {
                $this->template = 'login/default';
                $this->login_tools->log_out();
            } else if (!$this->log_in()) {
                $this->template = 'login/default';
                $this->public_core();
            } else if ($this->user->have_access_to($this->page->name)) {
                if ($name == __CLASS__) {
                    $this->template = 'index';
                } else {
                    $this->template = $name;
                    $this->set_default_items();
                    $this->pre_private_core();
                    $this->private_core();
                }
            } else if ($name == '') {
                $this->template = 'index';
            } else {
                $this->template = 'access_denied';
                $this->user->clean_cache(TRUE);
            }
        } else {
            $this->template = 'no_db';
            $this->new_error_msg('¡Imposible conectar con la base de datos <b>' . FS_DB_NAME . '</b>!');
        }
    }

    /**
     * Devuelve TRUE si hay actualizaciones pendientes (sólo si eres admin).
     * @return boolean
     */
    public function check_for_updates()
    {
        if (isset($this->fs_updated)) {
            return $this->fs_updated;
        }

        $this->fs_updated = FALSE;
        if ($this->user->admin) {
            $desactivado = defined('FS_DISABLE_MOD_PLUGINS') ? FS_DISABLE_MOD_PLUGINS : false;
            if ($desactivado) {
                $this->fs_updated = FALSE;
            } else {
                $fsvar = new fs_var();
                $this->fs_updated = (bool) $fsvar->simple_get('updates');
            }
        }

        return $this->fs_updated;
    }

    /**
     * Elimina la lista con los últimos cambios del usuario.
     */
    public function clean_last_changes()
    {
        $this->last_changes = [];
        $this->cache->delete('last_changes_' . $this->user->nick);
    }

    /**
     * Cierra la conexión con la base de datos.
     */
    public function close()
    {
        $this->db->close();
    }

    /**
     * Devuelve el objeto Request de Symfony HttpFoundation.
     * Permite acceso moderno a parámetros de la petición.
     * 
     * Uso:
     *   $this->getRequest()->query->get('id');     // GET parameter
     *   $this->getRequest()->request->get('name'); // POST parameter
     *   $this->getRequest()->get('field');         // GET o POST
     * 
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public function getRequest(): \Symfony\Component\HttpFoundation\Request
    {
        return $this->request;
    }

    /**
     * Valida el token CSRF para peticiones POST.
     * 
     * Modos de operación:
     * - Modo soft (por defecto): Log warning si falta token, pero permite continuar
     * - Modo estricto: Rechaza peticiones sin token válido (FS_CSRF_STRICT=true)
     * 
     * @param string|null $tokenId ID del token (por defecto 'fs_form')
     * @return bool True si la validación pasa
     */
    protected function validateCsrf(?string $tokenId = null): bool
    {
        // Solo validar peticiones POST
        if ($this->request->getMethod() !== 'POST') {
            return true;
        }

        // Modo estricto: definir FS_CSRF_STRICT=true para activar
        $strict = defined('FS_CSRF_STRICT') && FS_CSRF_STRICT;

        // Obtener token de POST o header (para AJAX)
        $token = $this->request->request->get(\FSFramework\Security\CsrfManager::FIELD_NAME)
              ?? $this->request->headers->get(\FSFramework\Security\CsrfManager::HEADER_NAME);

        if (empty($token)) {
            $msg = "CSRF: Token ausente en formulario POST ({$this->class_name})";
            error_log($msg);
            
            if ($strict) {
                $this->new_error_msg('Sesión expirada o token de seguridad faltante. Por favor, recarga la página.');
                $this->csrf_valid = false;
                return false;
            }
            
            // Modo soft: permitir pero marcar
            $this->csrf_valid = false;
            return true;
        }

        if (!\FSFramework\Security\CsrfManager::isValid($token, $tokenId)) {
            $msg = "CSRF: Token inválido en ({$this->class_name})";
            error_log($msg);
            
            if ($strict) {
                $this->new_error_msg('Token de seguridad inválido. Por favor, recarga la página.');
                $this->csrf_valid = false;
                return false;
            }
            
            // Modo soft: permitir pero marcar
            $this->csrf_valid = false;
            return true;
        }

        $this->csrf_valid = true;
        return true;
    }

    /**
     * Indica si el último POST tenía un token CSRF válido.
     * Útil para logging y auditoría durante la transición.
     * 
     * @return bool
     */
    public function isCsrfValid(): bool
    {
        return $this->csrf_valid;
    }

    /**
     * Indica si la petición actual es AJAX.
     * Detecta via X-Requested-With header o parámetro ajax=1.
     * 
     * @return bool
     */
    public function isAjax(): bool
    {
        return $this->is_ajax;
    }

    /**
     * Devuelve la lista de menús
     * @return array lista de menús
     */
    public function folders()
    {
        $folders = [];
        foreach ($this->menu as $m) {
            if ($m->folder != '' && $m->show_on_menu && !in_array($m->folder, $folders)) {
                $folders[] = $m->folder;
            }
        }
        return $folders;
    }

    /**
     * Devuelve la lista con los últimos cambios del usuario.
     * @return array
     */
    public function get_last_changes()
    {
        if (!isset($this->last_changes)) {
            $this->last_changes = $this->cache->get_array('last_changes_' . $this->user->nick);
        }

        return $this->last_changes;
    }

    /**
     * Muestra un consejo al usuario
     * @param string $msg el consejo a mostrar
     */
    public function new_advice($msg)
    {
        if ($this->class_name == $this->core_log->controller_name()) {
            /// solamente nos interesa mostrar los mensajes del controlador que inicia todo
            $this->core_log->new_advice($msg);
        }
    }

    /**
     * Añade un elemento a la lista de cambios del usuario.
     * @param string $txt texto descriptivo.
     * @param string $url URL del elemento (albarán, factura, artículos...).
     * @param boolean $nuevo TRUE si el elemento es nuevo, FALSE si se ha modificado.
     */
    public function new_change($txt, $url, $nuevo = FALSE)
    {
        $this->get_last_changes();
        if (count($this->last_changes) > 0) {
            if ($this->last_changes[0]['url'] == $url) {
                $this->last_changes[0]['nuevo'] = $nuevo;
            } else {
                array_unshift($this->last_changes, array('texto' => ucfirst($txt), 'url' => $url, 'nuevo' => $nuevo, 'cambio' => date('d-m-Y H:i:s')));
            }
        } else {
            array_unshift($this->last_changes, array('texto' => ucfirst($txt), 'url' => $url, 'nuevo' => $nuevo, 'cambio' => date('d-m-Y H:i:s')));
        }

        /// sólo queremos 10 elementos
        $num = 10;
        foreach ($this->last_changes as $i => $value) {
            if ($num > 0) {
                $num--;
            } else {
                unset($this->last_changes[$i]);
            }
        }

        $this->cache->set('last_changes_' . $this->user->nick, $this->last_changes);
    }

    /**
     * Muestra al usuario un mensaje de error
     * @param string $msg el mensaje a mostrar
     */
    public function new_error_msg($msg, $tipo = 'error', $alerta = FALSE, $guardar = TRUE)
    {
        if ($this->class_name == $this->core_log->controller_name()) {
            /// solamente nos interesa mostrar los mensajes del controlador que inicia todo
            $this->core_log->new_error($msg);
        }

        if ($guardar) {
            $this->core_log->save($msg, $tipo, $alerta);
        }
    }

    /**
     * Muestra al usuario un mensaje de error y lo imprime en la consola de JavaScript
     * @param string $msg el mensaje a mostrar
     * @param string $tipo el tipo de mensaje (por defecto 'error')
     * @param bool $alerta si se debe mostrar una alerta (por defecto FALSE)
     * @param bool $guardar si se debe guardar el mensaje en el log (por defecto TRUE)
     */
    public function new_error_console($msg, $tipo = 'error', $alerta = FALSE, $guardar = TRUE)
    {
        if ($this->class_name == $this->core_log->controller_name()) {
            /// solamente nos interesa mostrar los mensajes del controlador que inicia todo
            $this->core_log->new_error($msg);
        }

        if ($guardar) {
            $this->core_log->save($msg, $tipo, $alerta);
        }

        // Generar un script JavaScript para mostrar el mensaje en la consola
        echo "<script>console.log('" . addslashes($msg) . "');</script>";
    }

    /**
     * Muestra un mensaje al usuario
     * @param string $msg
     * @param boolean $save
     * @param string $tipo
     * @param boolean $alerta
     */
    public function new_message($msg, $save = FALSE, $tipo = 'msg', $alerta = FALSE)
    {
        if ($this->class_name == $this->core_log->controller_name()) {
            /// solamente nos interesa mostrar los mensajes del controlador que inicia todo
            $this->core_log->new_message($msg);
        }

        if ($save) {
            $this->core_log->save($msg, $tipo, $alerta);
        }
    }

    /**
     * Devuelve la lista de elementos de un menú seleccionado
     * @param string $folder el menú seleccionado
     * @return array lista de elementos del menú
     */
    public function pages($folder = '')
    {
        $pages = [];
        foreach ($this->menu as $page) {
            if ($folder == $page->folder && $page->show_on_menu && !in_array($page, $pages)) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    /**
     * Esta es la función principal que se ejecuta cuando el usuario ha hecho login
     */
    protected function private_core()
    {

    }

    /**
     * Función que se ejecuta si el usuario no ha hecho login
     */
    protected function public_core()
    {

    }

    /**
     * Devuelve el número de consultas SQL (SELECT) que se han ejecutado
     * @return integer
     */
    public function selects()
    {
        return $this->db->get_selects();
    }

    /**
     * Redirecciona a la página predeterminada para el usuario
     */
    public function select_default_page()
    {
        if (!$this->db->connected() || !$this->user->logged_on) {
            return;
        }

        if (!is_null($this->user->fs_page)) {
            header('Location: index.php?page=' . $this->user->fs_page);
            return;
        }

        /*
         * Cuando un usuario no tiene asignada una página por defecto,
         * se selecciona la primera página del menú.
         */
        $page = 'admin_home';
        foreach ($this->menu as $p) {
            if (!$p->show_on_menu) {
                continue;
            }

            $page = $p->name;
            if ($p->important) {
                break;
            }
        }
        header('Location: index.php?page=' . $page);
    }

    /**
     * Devuelve información del sistema para el informe de errores
     * @return string la información del sistema
     */
    public function system_info()
    {
        $txt = 'facturascripts: ' . $this->version() . "\n";

        if ($this->db->connected()) {
            if ($this->user->logged_on) {
                $txt .= 'os: ' . php_uname() . "\n";
                $txt .= 'php: ' . phpversion() . "\n";
                $txt .= 'database type: ' . FS_DB_TYPE . "\n";
                $txt .= 'database version: ' . $this->db->version() . "\n";

                if (defined('FS_FOREIGN_KEYS') && FS_FOREIGN_KEYS == 0) {
                    $txt .= "foreign keys: NO\n";
                }

                if ($this->cache->connected()) {
                    $txt .= "memcache: YES\n";
                    $txt .= 'memcache version: ' . $this->cache->version() . "\n";
                } else {
                    $txt .= "memcache: NO\n";
                }

                if (function_exists('curl_init')) {
                    $txt .= "curl: YES\n";
                } else {
                    $txt .= "curl: NO\n";
                }

                $txt .= "max input vars: " . ini_get('max_input_vars') . "\n";

                $txt .= 'plugins: ' . join(',', $GLOBALS['plugins']) . "\n";

                if ($this->check_for_updates()) {
                    $txt .= "updated: NO\n";
                }

                if (filter_input(INPUT_SERVER, 'REQUEST_URI')) {
                    $txt .= 'url: ' . filter_input(INPUT_SERVER, 'REQUEST_URI') . "\n------";
                }
            }
        } else {
            $txt .= 'os: ' . php_uname() . "\n";
            $txt .= 'php: ' . phpversion() . "\n";
            $txt .= 'database type: ' . FS_DB_TYPE . "\n";
        }

        foreach ($this->get_errors() as $e) {
            $txt .= "\n" . $e;
        }

        return str_replace('"', "'", $txt);
    }

    /**
     * Devuleve el número de transacciones SQL que se han ejecutado
     * @return integer
     */
    public function transactions()
    {
        return $this->db->get_transactions();
    }

    /**
     * Devuelve la URL de esta página (index.php?page=LO-QUE-SEA)
     * @return string
     */
    public function url()
    {
        return $this->page->url();
    }

    /**
     * Procesa los datos de la página o entrada en el menú
     * @param string $name
     * @param string $title
     * @param string $folder
     * @param boolean $shmenu
     * @param boolean $important
     */
    private function check_fs_page($name, $title, $folder, $shmenu, $important)
    {
        /// cargamos los datos de la página o entrada del menú actual
        $this->page = new fs_page(
            array(
                'name' => $name,
                'title' => $title,
                'folder' => $folder,
                'version' => $this->version(),
                'show_on_menu' => $shmenu,
                'important' => $important,
                'orden' => 100
            )
        );

        /// ahora debemos comprobar si guardar o no
        if ($name !== 'fs_controller') {
            $page = $this->page->get($name);
            if ($page) {
                /// la página ya existe ¿Actualizamos?
                if ($page->title != $title || $page->folder != $folder || $page->show_on_menu != $shmenu || $page->important != $important) {
                    error_log("Updating page $name: show_on_menu from " . ($page->show_on_menu ? 'TRUE' : 'FALSE') . " to " . ($shmenu ? 'TRUE' : 'FALSE'));
                    $page->title = $title;
                    $page->folder = $folder;
                    $page->show_on_menu = $shmenu;
                    $page->important = $important;
                    $page->save();
                }

                $this->page = $page;
            } else {
                /// la página no existe, guardamos.
                error_log("Creating new page $name with show_on_menu = " . ($shmenu ? 'TRUE' : 'FALSE'));
                $this->page->save();
            }
        }
    }

    private function load_extensions()
    {
        $fsext = new fs_extension();
        foreach ($fsext->all() as $ext) {
            /// Cargamos las extensiones para este controlador o para todos
            if (in_array($ext->to, [NULL, $this->class_name])) {
                $this->extensions[] = $ext;
            }
        }
    }

    /**
     * Carga el menú de FSFramework
     * @param boolean $reload TRUE si quieres recargar
     */
    protected function load_menu($reload = FALSE)
    {
        $this->menu = $this->user->get_menu($reload);
    }

    /**
     * Devuelve TRUE si el usuario realmente tiene acceso a esta página
     * @return boolean
     */
    private function log_in()
    {
        $this->login_tools->log_in($this->user);
        if ($this->user->logged_on) {
            $this->core_log->set_user_nick($this->user->nick);
            $this->load_menu();
        }

        return $this->user->logged_on;
    }

    private function pre_private_core()
    {
        $this->query = fs_filter_input_req('query');

        // Validar CSRF para peticiones POST (modo soft por defecto)
        $this->validateCsrf();

        /// quitamos extensiones de páginas a las que el usuario no tenga acceso
        foreach ($this->extensions as $i => $value) {
            if ($value->type != 'config' && !$this->user->have_access_to($value->from)) {
                unset($this->extensions[$i]);
            }
        }
    }

    /**
     * Establece un almacén como predeterminado para este usuario.
     * @param string $cod el código del almacén
     */
    protected function save_codalmacen($cod)
    {
        setcookie('default_almacen', $cod, time() + FS_COOKIES_EXPIRE);
        $this->default_items->set_codalmacen($cod);
    }

    /**
     * Establece un impuesto (IVA) como predeterminado para este usuario.
     * @param string $cod el código del impuesto
     */
    protected function save_codimpuesto($cod)
    {
        setcookie('default_impuesto', $cod, time() + FS_COOKIES_EXPIRE);
        $this->default_items->set_codimpuesto($cod);
    }

    /**
     * Establece una forma de pago como predeterminada para este usuario.
     * @param string $cod el código de la forma de pago
     */
    protected function save_codpago($cod)
    {
        setcookie('default_formapago', $cod, time() + FS_COOKIES_EXPIRE);
        $this->default_items->set_codpago($cod);
    }

    /**
     * Establecemos los elementos por defecto, pero no se guardan.
     * Para guardarlos hay que usar las funciones fs_controller::save_lo_que_sea().
     * La clase fs_default_items sólo se usa para indicar valores
     * por defecto a los modelos.
     */
    private function set_default_items()
    {
        /// gestionamos la página de inicio
        if (filter_input(INPUT_GET, 'default_page')) {
            if (filter_input(INPUT_GET, 'default_page') == 'FALSE') {
                $this->default_items->set_default_page(NULL);
                $this->user->fs_page = NULL;
            } else {
                $this->default_items->set_default_page($this->page->name);
                $this->user->fs_page = $this->page->name;
            }

            $this->user->save();
        } else if (is_null($this->default_items->default_page())) {
            $this->default_items->set_default_page($this->user->fs_page);
        }

        if (is_null($this->default_items->showing_page())) {
            $this->default_items->set_showing_page($this->page->name);
        }
    }

    /**
     * Convierte un precio de la divisa_desde a la divisa especificada.
     * Requires business_data plugin for full functionality.
     * 
     * @param float $precio
     * @param string $coddivisa_desde
     * @param string $coddivisa
     * @return float
     */
    public function divisa_convert($precio, $coddivisa_desde, $coddivisa)
    {
        if ($this->divisa_tools !== null) {
            return $this->divisa_tools->divisa_convert($precio, $coddivisa_desde, $coddivisa);
        }
        // Fallback: return price unchanged if business_data plugin not available
        return $precio;
    }

    /**
     * Convierte el precio en euros a la divisa preterminada de la empresa.
     * Por defecto usa las tasas de conversión actuales, pero si se especifica
     * coddivisa y tasaconv las usará.
     * Requires business_data plugin for full functionality.
     * 
     * @param float $precio
     * @param string $coddivisa
     * @param float $tasaconv
     * @return float
     */
    public function euro_convert($precio, $coddivisa = NULL, $tasaconv = NULL)
    {
        if ($this->divisa_tools !== null) {
            return $this->divisa_tools->euro_convert($precio, $coddivisa, $tasaconv);
        }
        // Fallback: return price unchanged if business_data plugin not available
        return $precio;
    }

    /**
     * Devuelve un string con el número en el formato de número predeterminado.
     * Requires business_data plugin for full functionality.
     * 
     * @param float $num
     * @param integer $decimales
     * @param boolean $js
     * @return string
     */
    public function show_numero($num = 0, $decimales = FS_NF0, $js = FALSE)
    {
        if ($this->divisa_tools !== null) {
            return $this->divisa_tools->show_numero($num, $decimales, $js);
        }
        // Fallback: basic number formatting
        $num = $num ?? 0;
        $decimales = $decimales ?? 2;
        if ($js) {
            return number_format($num, $decimales, '.', '');
        }
        return number_format($num, $decimales, ',', '.');
    }

    /**
     * Devuelve un string con el precio en el formato predefinido y con la
     * divisa seleccionada (o la predeterminada).
     * Requires business_data plugin for full functionality.
     * 
     * @param float $precio
     * @param string $coddivisa
     * @param string $simbolo
     * @param integer $dec nº de decimales
     * @return string
     */
    public function show_precio($precio = 0, $coddivisa = FALSE, $simbolo = TRUE, $dec = FS_NF0)
    {
        if ($this->divisa_tools !== null) {
            return $this->divisa_tools->show_precio($precio, $coddivisa, $simbolo, $dec);
        }
        // Fallback: basic price formatting with EUR symbol
        $precio = $precio ?? 0;
        $dec = $dec ?? 2;
        $formatted = number_format($precio, $dec, ',', '.');
        return $simbolo ? $formatted . ' €' : $formatted;
    }

    /**
     * Devuelve el símbolo de divisa predeterminado
     * o bien el símbolo de la divisa seleccionada.
     * Requires business_data plugin for full functionality.
     * 
     * @param string $coddivisa
     * @return string
     */
    public function simbolo_divisa($coddivisa = FALSE)
    {
        if ($this->divisa_tools !== null) {
            return $this->divisa_tools->simbolo_divisa($coddivisa);
        }
        // Fallback: return EUR symbol if business_data plugin not available
        return '€';
    }
}
