<?php
namespace FSFramework\Controller;

use FSFramework\Attribute\FSRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modern Symfony Controller for FSFramework Updater.
 * This provides a more robust and cleaner way to handle updates.
 */
#[FSRoute('/updater', methods: ['GET', 'POST'], name: 'updater')]
class UpdaterController
{
    /**
     * @var fs_updater
     */
    private $updater;

    /**
     * Legacy constructor for index.php compatibility.
     * When instantiated by legacy index.php, we hijack execution to use the modern handler.
     */
    public function __construct()
    {
        // If we are in a legacy context (index.php), handle request and exit.
        // This prevents index.php from trying to access missing properties like $template or calling close().
        if (basename($_SERVER['PHP_SELF']) === 'index.php') {
            $request = Request::createFromGlobals();
            $response = $this->handle($request);
            $response->send();
            exit();
        }
    }

    public function handle(Request $request): Response
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        
        // Cargamos configuración si no está cargada
        if (!defined('FS_DB_NAME')) {
            require_once $root . '/config.php';
            require_once $root . '/base/config2.php';
        }

        require_once $root . '/base/fs_updater.php';
        
        // Limpiar caché de archivos (requisito del usuario: solo archivos)
        $cache = new \fs_cache();
        $cache->clean();

        $updater = new \fs_updater();
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/xhtml" lang="es" xml:lang="es">
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title>Actualizador de FSFramework</title>
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <!-- Bootstrap CSS -->
            <link rel="stylesheet" href="view/css/bootstrap.min.css" />
            <!-- Font Awesome -->
            <link rel="stylesheet" href="view/css/font-awesome.min.css" />
            <!-- AdminLTE Theme -->
            <link rel="stylesheet" href="themes/AdminLTE/css/AdminLTE.min.css" />
            <link rel="stylesheet" href="themes/AdminLTE/css/skins/skin-blue.min.css" />
            <!-- jQuery y Bootstrap JS -->
            <script type="text/javascript" src="themes/AdminLTE/js/jQuery-2.1.4.min.js"></script>
            <script type="text/javascript" src="view/js/bootstrap.min.js"></script>
            <!-- AdminLTE JS -->
            <script type="text/javascript" src="themes/AdminLTE/js/adminlte.min.js"></script>
        </head>
        <body class="hold-transition skin-blue layout-top-nav">
            <div class="wrapper">
                <div class="content-wrapper" style="margin-left: 0;">
                    <section class="content-header">
                        <h1>
                            <i class="fa fa-upload"></i> Actualizador de FSFramework
                        </h1>
                    </section>
                    <section class="content">
                        <div class="row">
                            <div class="col-sm-12">
                                <a href="index.php?page=admin_home&updated=TRUE" class="btn btn-sm btn-default">
                                    <i class="fa fa-arrow-left"></i>
                                    <span class="hidden-xs">&nbsp;Panel de control</span>
                                </a>
                                <br /><br />
                                <?php
                                if (count($updater->get_errors()) > 0) {
                                    echo '<div class="alert alert-danger"><ul>';
                                    foreach ($updater->get_errors() as $error) {
                                        echo '<li>' . $error . '</li>';
                                    }
                                    echo '</ul></div>';
                                }
                                if (count($updater->get_messages()) > 0) {
                                    echo '<div class="alert alert-info"><ul>';
                                    foreach ($updater->get_messages() as $msg) {
                                        echo '<li>' . $msg . '</li>';
                                    }
                                    echo '</ul></div>';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="box box-primary">
                                    <div class="box-header with-border">
                                        <h3 class="box-title">Actualizaciones y Opciones</h3>
                                    </div>
                                    <div class="box-body">
                                        <ul class="nav nav-tabs" role="tablist">
                                            <li role="presentation" class="active"><a href="#actualizaciones" role="tab" data-toggle="tab"><i class="fa fa-download"></i> Actualizaciones</a></li>
                                            <li role="presentation"><a href="#opciones" role="tab" data-toggle="tab"><i class="fa fa-cog"></i> Opciones</a></li>
                                        </ul>
                                        <div class="tab-content" style="padding-top: 15px;">
                                            <div role="tabpanel" class="tab-pane active" id="actualizaciones">
                                                <table class="table table-hover table-striped">
                                                    <thead><tr><th>Nombre</th><th>Descripción</th><th class="text-right">Versión</th><th class="text-right">Nueva</th><th></th></tr></thead>
                                                    <?php echo $updater->tr_updates; ?>
                                                </table>
                                            </div>
                                            <div role="tabpanel" class="tab-pane" id="opciones">
                                                <table class="table table-hover table-striped">
                                                    <thead><tr><th>Opción</th><th></th></tr></thead>
                                                    <?php echo $updater->tr_options; ?>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section><!-- /.content -->
                </div><!-- /.content-wrapper -->
                
                <footer class="main-footer" style="margin-left: 0;">
                    <div class="pull-right hidden-xs">
                        <span class="label label-default"><?php echo $updater->duration(); ?></span>
                    </div>
                    <strong>FSFramework</strong> Symfony-Powered Updater
                </footer>
            </div><!-- /.wrapper -->
        </body>
        </html>
        <?php
        return new Response(ob_get_clean(), 200);
    }
}
