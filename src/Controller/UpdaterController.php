<?php

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
        $cache = new fs_cache();
        $cache->clean();

        $updater = new fs_updater();
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/xhtml" lang="es" xml:lang="es">
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title>Actualizador de FSFramework</title>
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <link rel="stylesheet" href="view/css/bootstrap-yeti.min.css" />
            <link rel="stylesheet" href="view/css/font-awesome.min.css" />
            <script type="text/javascript" src="view/js/jquery.min.js"></script>
            <script type="text/javascript" src="view/js/bootstrap.min.js"></script>
        </head>
        <body>
            <br />
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-12">
                        <a href="index.php?page=admin_home&updated=TRUE" class="btn btn-sm btn-default">
                            <span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span>
                            <span class="hidden-xs">&nbsp;Panel de control</span>
                        </a>
                        <div class="page-header">
                            <h1><span class="glyphicon glyphicon-upload" aria-hidden="true"></span> Actualizador Moderno</h1>
                        </div>
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
                        <ul class="nav nav-tabs" role="tablist">
                            <li role="presentation" class="active"><a href="#actualizaciones" role="tab" data-toggle="tab">Actualizaciones</a></li>
                            <li role="presentation"><a href="#opciones" role="tab" data-toggle="tab">Opciones</a></li>
                        </ul>
                        <div class="tab-content">
                            <div role="tabpanel" class="tab-pane active" id="actualizaciones">
                                <table class="table table-hover">
                                    <thead><tr><th>Nombre</th><th>Descripción</th><th class="text-right">Versión</th><th class="text-right">Nueva</th><th></th></tr></thead>
                                    <?php echo $updater->tr_updates; ?>
                                </table>
                            </div>
                            <div role="tabpanel" class="tab-pane" id="opciones">
                                <table class="table table-hover">
                                    <thead><tr><th>Opción</th><th></th></tr></thead>
                                    <?php echo $updater->tr_options; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <br /><br />
            <div class="container-fluid">
                <div class="row"><div class="col-sm-12"><hr /></div></div>
                <div class="row">
                    <div class="col-xs-6"><small>FSFramework Symfony-Powered Updater</small></div>
                    <div class="col-xs-6 text-right"><span class="label label-default"><?php echo $updater->duration(); ?></span></div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return new Response(ob_get_clean(), 200);
    }
}
