<?php

namespace FacturaScripts\Core\Base;

use FacturaScripts\Core\Html;

/**
 * Modern Controller base class for FSFramework.
 * Bridge for FacturaScripts 2025 plugins to work with FSFramework.
 * Mimics fs_controller for template compatibility.
 */
class Controller
{
    /**
     * Title of the page.
     * @var string
     */
    public $title = '';

    /**
     * Template to render (without extension).
     * @var string|false
     */
    protected $template = null;

    /**
     * Database connection (for direct queries).
     * @var \fs_db2
     */
    protected $dataBase;
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
    public $empresa;

    /**
     * Base path for assets (FS_PATH constant or empty string)
     * @var string
     */
    public $fs_path = '';

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
        $this->dataBase = $this->db; // Alias for FS2025 compatibility
        $this->cache = new \fs_cache();
        $this->core_log = new \fs_core_log($this->className);
        $this->login_tools = new \fs_login();
        
        // Set fs_path for templates
        $this->fs_path = defined('FS_PATH') ? FS_PATH : '';

        if ($this->db->connect()) {
            // Use fs_user directly for full compatibility with header.html
            $this->user = new \fs_user();

            $pageData = $this->getPageData();
            
            // Check if page already exists in database
            $tempPage = new \fs_page();
            $existingPage = $tempPage->get($pageData['name']);
            
            if ($existingPage) {
                // Update existing page with new data from controller
                $existingPage->title = $pageData['title'];
                $existingPage->folder = $pageData['menu'];
                $existingPage->show_on_menu = $pageData['showonmenu'] ?? true;
                $existingPage->orden = $pageData['ordernum'] ?? 100;
                $existingPage->save();
                $this->page = $existingPage;
            } else {
                // Create new page for FS2025 controller
                $this->page = new \fs_page([
                    'name' => $pageData['name'],
                    'title' => $pageData['title'],
                    'folder' => $pageData['menu'],
                    'show_on_menu' => $pageData['showonmenu'] ?? true,
                    'important' => false,
                    'orden' => $pageData['ordernum'] ?? 100
                ]);
                $this->page->save();
                
                // Clear page cache so the new page appears in menus immediately
                $this->cache->delete('m_fs_page_all');
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
            // In FS2025, admins have automatic access to all pages
            $hasAccess = $this->user->admin || $this->user->have_access_to($this->page->name);
            
            if (!$hasAccess) {
                // Access Denied
                echo \FacturaScripts\Core\Html::render('access_denied', ['fsc' => $this]);
                exit;
            }
            
            // Auto-grant access for admins to new FS2025 pages (so they appear in menu)
            if ($this->user->admin && !$this->user->have_access_to($this->page->name)) {
                // Create access record for this admin user
                $access = new \fs_access();
                $access->fs_user = $this->user->nick;
                $access->fs_page = $this->page->name;
                $access->allow_delete = true;
                $access->save();
                
                // Refresh the menu cache
                $this->user->clean_cache(true);
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

    /**
     * Run the controller and render the template.
     */
    public function run(): void
    {
        // Set title from page data
        $pageData = $this->getPageData();
        $this->title = $pageData['title'] ?? $this->className;

        // Execute main logic
        $this->privateCore($this->response, $this->user, $this->permissions);

        // Render template if set
        if ($this->template !== false) {
            $templateName = $this->template ?? $this->className;
            echo Html::render($templateName, [
                'fsc' => $this,
                'user' => $this->user,
                'empresa' => $this->empresa,
                'i18n' => new \FSFramework\Translation\FSTranslator(),
            ]);
        }
    }

    /**
     * Set the template to render (or false to disable rendering).
     * @param string|false $template
     */
    public function setTemplate($template): void
    {
        $this->template = $template;
    }

    /**
     * Get the current template.
     * @return string|false|null
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Validate the CSRF form token.
     * @return bool
     */
    protected function validateFormToken(): bool
    {
        // Get token from POST (multireqtoken is used by FS2025)
        $token = $this->request->request->get('multireqtoken')
            ?? $this->request->request->get(\FSFramework\Security\CsrfManager::FIELD_NAME)
            ?? $this->request->query->get('multireqtoken');

        if (empty($token)) {
            error_log("CSRF: Token missing in form submission ({$this->className})");
            return false;
        }

        // Use FSFramework CSRF manager
        if (!\FSFramework\Security\CsrfManager::isValid($token)) {
            error_log("CSRF: Invalid token in ({$this->className})");
            return false;
        }

        return true;
    }

    /**
     * Redirect to another page.
     * @param string $url
     */
    public function redirect(string $url): void
    {
        // If just a page name, build full URL
        if (!str_contains($url, '://') && !str_starts_with($url, '/') && !str_starts_with($url, 'index.php')) {
            $url = 'index.php?page=' . $url;
        }
        
        header('Location: ' . $url);
        exit;
    }

    /**
     * Add an error message to the log.
     * @param string $msg
     */
    public function new_error_msg(string $msg): void
    {
        $this->core_log->new_error($msg);
    }

    /**
     * Add a message to the log.
     * @param string $msg
     */
    public function new_message(string $msg): void
    {
        $this->core_log->new_message($msg);
    }

    /**
     * Add an advice message to the log.
     * @param string $msg
     */
    public function new_advice(string $msg): void
    {
        $this->core_log->new_advice($msg);
    }
}

