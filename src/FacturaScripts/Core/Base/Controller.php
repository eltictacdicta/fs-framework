<?php

namespace FacturaScripts\Core\Base;

/**
 * Modern Controller base class for FSFramework.
 * Mimics fs_controller for template compatibility.
 */
class Controller
{
    /**
     * Legacy page object for compatibility.
     * @var \fs_page
     */
    public $page;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    public $request;

    public $response;

    /**
     * User object - use fs_user directly for full compatibility
     * @var \fs_user
     */
    public $user;

    public $permissions;
    public $className;

    /**
     * Menu items
     * @var array
     */
    public $menu = [];

    /**
     * Extensions
     * @var array
     */
    public $extensions = [];

    /**
     * Empresa object
     * @var object|null
     */
    /**
     * Empresa object
     * @var object|null
     */
    public $empresa;

    /**
     * @var \fs_db2
     */
    public $db;

    /**
     * @var \fs_cache
     */
    public $cache;

    /**
     * @var \fs_login
     */
    private $login_tools;

    private $fs_updated;
    private $last_changes;
    protected $core_log;
    private $uptime;

    public function __construct(string $className = '', string $uri = '')
    {
        $tiempo = explode(' ', microtime());
        $this->uptime = $tiempo[1] + $tiempo[0];

        $this->className = $className ?: (new \ReflectionClass($this))->getShortName();
        $this->request = \FSFramework\Core\Kernel::request();

        // Initialize Core tools
        $this->db = new \fs_db2();
        $this->cache = new \fs_cache();
        $this->core_log = new \fs_core_log($this->className);
        $this->login_tools = new \fs_login();

        if ($this->db->connect()) {
            // Use fs_user directly for full compatibility with header.html
            $this->user = new \fs_user();

            $pageData = $this->getPageData();
            $this->page = new \fs_page([
                'name' => $pageData['name'],
                'title' => $pageData['title'],
                'folder' => $pageData['menu'],
                'show_on_menu' => $pageData['showonmenu'],
                'important' => false,
                'orden' => $pageData['ordernum']
            ]);

            // Auto-save page if it doesn't exist (shim behavior for legacy)
            if (!$this->page->exists()) {
                $this->page->save();
            }

            // Initialize response and permissions
            $this->response = new \FacturaScripts\Core\Response();
            $this->permissions = new \FacturaScripts\Core\Base\ControllerPermissions();

            // 1. Authenticate immediately
            $this->login_tools->log_in($this->user);

            if (isset($_COOKIE['fsNick']) && !$this->user->logged_on) {
                // Retry loading? login_tools->log_in should have handled it if cookie is valid session.
            }

            if ($this->request->query->has('logout')) {
                $this->login_tools->log_out();
                header('Location: index.php');
                exit;
            }

            // 2. Enforce Access Control
            if (!$this->user->logged_on) {
                // For now, simple forceful redirect/render to protect content.
                // We exit here to prevent 'handle()' or 'run()' from executing sensitive logic.
                echo \FacturaScripts\Core\Html::render('login/default', [
                    'fsc' => $this,
                    'user' => $this->user,
                    'empresa' => $this->empresa
                ]);
                exit;
            }

            // Check Page Permissions
            if (!$this->user->have_access_to($this->page->name)) {
                // Access Denied
                echo \FacturaScripts\Core\Html::render('access_denied', ['fsc' => $this]);
                exit;
            }

            // Load menu for header template (only if logged in)
            $this->menu = $this->user->get_menu();

            // Load empresa for header template
            if (class_exists('empresa')) {
                $emp = new \empresa();
                $empresa_data = $emp->get();
                if ($empresa_data) {
                    $this->empresa = $empresa_data;
                } else {
                    $this->empresa = $emp;
                }
            }
        }
    }

    /**
     * Return the basic data for this page.
     * To be overridden by subclasses.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return [
            'name' => $className,
            'title' => $className,
            'icon' => 'fa-solid fa-circle',
            'menu' => 'new',
            'submenu' => null,
            'showonmenu' => true,
            'ordernum' => 100
        ];
    }

    /**
     * Placeholder for the main logic.
     */
    public function privateCore(&$response, $user, $permissions)
    {
    }

    /**
     * @return string
     */
    public function url()
    {
        return $this->page->url();
    }

    public function folders()
    {
        $folders = [];
        if (empty($this->menu)) {
            return $folders;
        }

        foreach ($this->menu as $m) {
            $folder = is_object($m) ? $m->folder : ($m['folder'] ?? '');
            $show = is_object($m) ? $m->show_on_menu : ($m['show_on_menu'] ?? false);

            if ($folder != '' && $show && !in_array($folder, $folders)) {
                $folders[] = $folder;
            }
        }
        return $folders;
    }

    public function pages($folder = '')
    {
        $pages = [];
        if (empty($this->menu)) {
            return $pages;
        }

        foreach ($this->menu as $p) {
            $pFolder = is_object($p) ? $p->folder : ($p['folder'] ?? '');
            $pShow = is_object($p) ? $p->show_on_menu : ($p['show_on_menu'] ?? false);

            if ($folder == $pFolder && $pShow) {
                $pages[] = $p;
            }
        }
        return $pages;
    }

    /**
     * Returns TRUE if there are pending updates (only if you are admin).
     * @return boolean
     */
    public function check_for_updates()
    {
        if (isset($this->fs_updated)) {
            return $this->fs_updated;
        }

        $this->fs_updated = false;
        if ($this->user->admin) {
            $desactivado = defined('FS_DISABLE_MOD_PLUGINS') ? FS_DISABLE_MOD_PLUGINS : false;
            if ($desactivado) {
                $this->fs_updated = false;
            } else {
                $fsvar = new \fs_var();
                $this->fs_updated = (bool) $fsvar->simple_get('updates');
            }
        }

        return $this->fs_updated;
    }

    /**
     * Returns the list with the latest changes of the user.
     * @return array
     */
    public function get_last_changes()
    {
        if (!isset($this->last_changes)) {
            if ($this->cache) {
                $this->last_changes = $this->cache->get_array('last_changes_' . $this->user->nick);
            } else {
                $this->last_changes = [];
            }
        }

        return $this->last_changes;
    }

    public function get_errors()
    {
        return $this->core_log->get_errors();
    }

    public function get_messages()
    {
        return $this->core_log->get_messages();
    }

    public function get_advices()
    {
        return $this->core_log->get_advices();
    }


    public function get_js_location($js)
    {
        return 'view/js/' . $js;
    }

    /**
     * Returns today's date for cache busting in templates
     * @return string
     */
    public function today()
    {
        return date('Y-m-d');
    }

    /**
     * Returns the version string
     * @return string
     */
    public function version()
    {
        if (file_exists(FS_FOLDER . '/VERSION')) {
            return trim(file_get_contents(FS_FOLDER . '/VERSION'));
        }
        return '2024';
    }

    public function system_info()
    {
        return 'FS MÓDERN';
    }

    public function duration()
    {
        $tiempo = explode(" ", microtime());
        return (number_format($tiempo[1] + $tiempo[0] - $this->uptime, 3) . ' s');
    }

    public function get_db_history()
    {
        return $this->core_log->get_sql_history();
    }

    public function selects()
    {
        return $this->db->get_selects();
    }

    /**
     * Returns the number of SQL transactions executed
     * @return integer
     */
    public function transactions()
    {
        return $this->db->get_transactions();
    }

    public function run(): void
    {
        $this->privateCore($this->response, $this->user, $this->permissions);
    }
}

