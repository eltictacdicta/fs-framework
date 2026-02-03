<?php
/**
 * Controlador del Actualizador de FSFramework
 * 
 * Este controlador está diseñado para ser independiente del framework principal,
 * permitiendo que el módulo update-and-backup se actualice de forma autónoma.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

// No usamos namespace para que sea independiente
// use Symfony\Component\HttpFoundation\Request;
// use Symfony\Component\HttpFoundation\Response;

class UpdaterController
{
    /**
     * @var \fs_updater
     */
    private $updater;

    /**
     * @var \updater_manager
     */
    private $updaterManager;

    /**
     * @var \fs_backup_manager
     */
    private $backupManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Constructor vacío, la lógica está en handle()
    }

    /**
     * Maneja la petición del actualizador
     * 
     * @return void
     */
    public function handle()
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__);
        
        // Cargamos configuración si no está cargada
        if (!defined('FS_DB_NAME')) {
            require_once $root . '/config.php';
            require_once $root . '/base/config2.php';
        }

        require_once $root . '/base/fs_updater.php';
        require_once __DIR__ . '/fs_backup_manager.php';
        require_once __DIR__ . '/updater_manager.php';
        
        // Limpiar caché de archivos
        if (class_exists('fs_cache')) {
            $cache = new \fs_cache();
            $cache->clean();
        }

        $this->updater = new \fs_updater();
        $this->backupManager = new \fs_backup_manager();
        $this->updaterManager = new \updater_manager();
        
        // Procesar acciones
        $backupMessage = '';
        $backupError = '';
        $updaterMessage = '';
        $updaterError = '';
        
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        
        // Acción de actualizar el actualizador
        if ($action === 'update_updater') {
            if ($this->updaterManager->update_updater()) {
                $updaterMessage = 'Actualizador actualizado correctamente.';
            } else {
                $updaterError = implode(', ', $this->updaterManager->get_errors());
            }
        }
        
        if ($action === 'create_backup') {
            $result = $this->backupManager->create_backup();
            if ($result['complete']['success']) {
                $backupMessage = 'Copia de seguridad creada: ' . $result['complete']['backup_name'];
            } else {
                $backupError = 'Error al crear backup: ' . implode(', ', $this->backupManager->get_errors());
            }
        } elseif ($action === 'delete_backup' && isset($_GET['file'])) {
            if ($this->backupManager->delete_backup($_GET['file'])) {
                $backupMessage = 'Backup eliminado correctamente';
            } else {
                $backupError = implode(', ', $this->backupManager->get_errors());
            }
        }
        
        $backups = $this->backupManager->list_backups();
        $updaterInfo = $this->updaterManager->get_info();
        $updaterUpdate = $this->updaterManager->check_for_updates();
        $updater = $this->updater;
        
        // Renderizar la vista
        $this->render($updater, $backups, $updaterInfo, $updaterUpdate, 
                      $backupMessage, $backupError, $updaterMessage, $updaterError);
    }

    /**
     * Renderiza la vista del actualizador
     */
    private function render($updater, $backups, $updaterInfo, $updaterUpdate,
                           $backupMessage, $backupError, $updaterMessage, $updaterError)
    {
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
                            <small>v<?php echo $updaterInfo['version']; ?></small>
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
                                if ($backupMessage) {
                                    echo '<div class="alert alert-success">' . htmlspecialchars($backupMessage) . '</div>';
                                }
                                if ($backupError) {
                                    echo '<div class="alert alert-danger">' . htmlspecialchars($backupError) . '</div>';
                                }
                                if ($updaterMessage) {
                                    echo '<div class="alert alert-success">' . htmlspecialchars($updaterMessage) . '</div>';
                                }
                                if ($updaterError) {
                                    echo '<div class="alert alert-danger">' . htmlspecialchars($updaterError) . '</div>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <!-- Info del Actualizador -->
                        <?php if ($updaterUpdate): ?>
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="callout callout-info">
                                    <h4><i class="fa fa-refresh"></i> Actualización del Actualizador Disponible</h4>
                                    <p>
                                        Hay una nueva versión del actualizador disponible.
                                        <strong>Versión actual:</strong> <?php echo $updaterInfo['version']; ?> |
                                        <strong>Nueva versión:</strong> <?php echo $updaterUpdate['new_version']; ?>
                                    </p>
                                    <p>
                                        <a href="updater.php?action=update_updater" class="btn btn-info"
                                           onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Actualizando...'; this.disabled=true;">
                                            <i class="fa fa-download"></i> Actualizar Actualizador
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="box box-primary">
                                    <div class="box-header with-border">
                                        <h3 class="box-title">Actualizaciones, Opciones y Copias de Seguridad</h3>
                                    </div>
                                    <div class="box-body">
                                        <ul class="nav nav-tabs" role="tablist">
                                            <li role="presentation" class="active"><a href="#actualizaciones" role="tab" data-toggle="tab"><i class="fa fa-download"></i> Actualizaciones</a></li>
                                            <li role="presentation"><a href="#backups" role="tab" data-toggle="tab"><i class="fa fa-database"></i> Copias de Seguridad</a></li>
                                            <li role="presentation"><a href="#updater_info" role="tab" data-toggle="tab"><i class="fa fa-cog"></i> Info Actualizador</a></li>
                                            <li role="presentation"><a href="#opciones" role="tab" data-toggle="tab"><i class="fa fa-wrench"></i> Opciones</a></li>
                                        </ul>
                                        <div class="tab-content" style="padding-top: 15px;">
                                            <div role="tabpanel" class="tab-pane active" id="actualizaciones">
                                                <table class="table table-hover table-striped">
                                                    <thead><tr><th>Nombre</th><th>Descripción</th><th class="text-right">Versión</th><th class="text-right">Nueva</th><th></th></tr></thead>
                                                    <?php echo $updater->tr_updates; ?>
                                                </table>
                                            </div>
                                            <div role="tabpanel" class="tab-pane" id="backups">
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <a href="updater.php?action=create_backup" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Creando...'; this.disabled=true;">
                                                            <i class="fa fa-plus"></i> Crear Copia de Seguridad
                                                        </a>
                                                        <hr>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <h4><i class="fa fa-list"></i> Copias Disponibles</h4>
                                                        <?php if (empty($backups)): ?>
                                                            <div class="alert alert-info">No hay copias de seguridad disponibles.</div>
                                                        <?php else: ?>
                                                            <table class="table table-hover table-striped">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Archivo</th>
                                                                        <th>Tipo</th>
                                                                        <th>Tamaño</th>
                                                                        <th>Fecha</th>
                                                                        <th>Acciones</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                <?php foreach ($backups as $backup): ?>
                                                                    <tr>
                                                                        <td><i class="fa fa-<?php echo $backup['type'] === 'database' ? 'database' : 'file-archive-o'; ?>"></i> <?php echo htmlspecialchars($backup['name']); ?></td>
                                                                        <td><span class="label label-<?php echo $backup['type'] === 'database' ? 'info' : 'success'; ?>"><?php echo $backup['type']; ?></span></td>
                                                                        <td><?php echo $backup['size_formatted']; ?></td>
                                                                        <td><?php echo $backup['date']; ?></td>
                                                                        <td>
                                                                            <a href="updater.php?action=delete_backup&file=<?php echo urlencode($backup['name']); ?>" 
                                                                               class="btn btn-xs btn-danger" 
                                                                               onclick="return confirm('¿Eliminar esta copia de seguridad?');">
                                                                                <i class="fa fa-trash"></i> Eliminar
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        <?php endif; ?>
                                                        <p class="text-muted">
                                                            <i class="fa fa-info-circle"></i> 
                                                            Las copias se almacenan en: <code>update-and-backup/data/</code><br>
                                                            Se mantienen automáticamente las últimas 5 copias.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div role="tabpanel" class="tab-pane" id="updater_info">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="box box-info">
                                                            <div class="box-header with-border">
                                                                <h4 class="box-title"><i class="fa fa-info-circle"></i> Información del Actualizador</h4>
                                                            </div>
                                                            <div class="box-body">
                                                                <table class="table table-striped">
                                                                    <tr>
                                                                        <th>Nombre:</th>
                                                                        <td><?php echo htmlspecialchars($updaterInfo['name']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Versión:</th>
                                                                        <td><span class="label label-primary"><?php echo $updaterInfo['version']; ?></span></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Descripción:</th>
                                                                        <td><?php echo htmlspecialchars($updaterInfo['description']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Última actualización:</th>
                                                                        <td><?php echo $updaterInfo['last_update'] ?? 'No disponible'; ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Autor:</th>
                                                                        <td><?php echo htmlspecialchars($updaterInfo['author']); ?></td>
                                                                    </tr>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="box box-warning">
                                                            <div class="box-header with-border">
                                                                <h4 class="box-title"><i class="fa fa-refresh"></i> Estado de Actualizaciones</h4>
                                                            </div>
                                                            <div class="box-body">
                                                                <?php if ($updaterUpdate): ?>
                                                                <div class="alert alert-info">
                                                                    <h4><i class="fa fa-exclamation-circle"></i> Actualización Disponible</h4>
                                                                    <p>Nueva versión: <strong><?php echo $updaterUpdate['new_version']; ?></strong></p>
                                                                    <a href="updater.php?action=update_updater" class="btn btn-info"
                                                                       onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Actualizando...'; this.disabled=true;">
                                                                        <i class="fa fa-download"></i> Actualizar Ahora
                                                                    </a>
                                                                </div>
                                                                <?php else: ?>
                                                                <div class="alert alert-success">
                                                                    <h4><i class="fa fa-check-circle"></i> Actualizado</h4>
                                                                    <p>Tienes la última versión del actualizador.</p>
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
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
                    <strong>FSFramework</strong> Update and Backup Module v<?php echo $updaterInfo['version']; ?>
                </footer>
            </div><!-- /.wrapper -->
        </body>
        </html>
        <?php
    }
}
