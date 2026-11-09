<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FSFramework\Core\Http;

use FSFramework\Core\Html;
use Symfony\Component\HttpFoundation\Request;

/**
 * The ?page= dispatch that used to be inline in index.php: FS2025 page
 * controllers (plugins' Controller/) first, then legacy fs_controller pages,
 * then the template render. It writes its output and headers directly, as
 * it always did, and may exit.
 */
final class PageDispatcher
{
    /**
     * Accept only canonical page identifiers used by legacy and FS2025 page controllers.
     * null: no ?page= at all; '': present but invalid.
     */
    public static function requestedPageName(): ?string
    {
        $page = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
        if (!is_string($page)) {
            return null;
        }

        $page = trim($page);
        if ($page === '') {
            return '';
        }

        return preg_match('/^[a-z][a-z0-9_]*$/', $page) ? $page : '';
    }

    public function dispatch(Request $request): void
    {
        /// ampliamos el límite de ejecución de PHP a 5 minutos
        @set_time_limit(300);

        self::defineBaseUrl();
        require_once FS_FOLDER . '/base/fs_list_controller.php';
        require_once FS_FOLDER . '/base/fs_log_manager.php';

        /// capturar los fatal error: información importante para depurar
        register_shutdown_function('fatal_handler');

        /// ¿Qué controlador usar?
        $requestedPage = self::requestedPageName();
        $invalidPageRequested = ($requestedPage === '');
        $pagename = $requestedPage ?? '';
        if ($requestedPage === null && defined('FS_HOMEPAGE')) {
            $pagename = FS_HOMEPAGE;
        }

        if ($pagename == '') {
            if ($invalidPageRequested) {
                header('HTTP/1.0 404 Not Found');
            }

            $fsc = new \fs_controller();
        } else {
            // Controlador FS2025 (Controller/ de un plugin activo): una sola búsqueda
            // y un solo despacho. Si lanza, no se cae al legacy: ocultaría el error.
            $modernController = find_modern_controller($pagename);

            if ($modernController) {
                try {
                    $fsc = new $modernController['class']();
                    if (method_exists($fsc, 'handle')) {
                        $resp = $fsc->handle($request);
                        if ($resp) {
                            $resp->send();
                        }
                        exit;
                    }
                    if (method_exists($fsc, 'run')) {
                        $fsc->run();
                        exit;
                    }
                } catch (\Throwable $exc) {
                    if (!headers_sent()) {
                        http_response_code(500);
                    }
                    echo fs_render_fatal_error(
                        'FS2025',
                        $exc->getMessage(),
                        $exc->getFile(),
                        $exc->getLine(),
                        get_class($exc)
                    );
                    exit;
                }
            } else {
                // Legacy controller handling — use absolute path so CWD cannot break includes (LiteSpeed/LSAPI)
                $class_path = find_controller($pagename);
                require_once fs_is_absolute_path($class_path) ? $class_path : FS_FOLDER . '/' . $class_path;

                try {
                    /// ¿No se ha encontrado el controlador?
                    if ('base/fs_controller.php' === $class_path) {
                        header('HTTP/1.0 404 Not Found');
                        $fsc = new \fs_controller();
                    } else {
                        $legacyControllerClass = basename($class_path, '.php');
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $legacyControllerClass) || !class_exists($legacyControllerClass)) {
                            throw new \RuntimeException('Legacy controller class not found for page: ' . $pagename);
                        }

                        $fsc = new $legacyControllerClass();
                    }
                } catch (\Exception $exc) {
                    echo fs_render_fatal_error('legacy', $exc->getMessage(), $exc->getFile(), $exc->getLine(), get_class($exc));
                    (new \fs_log_manager())->save();
                    die();
                }
            }
        }

        /// guardamos los errores en el log
        $log_manager = new \fs_log_manager();
        $log_manager->save();

        /// redireccionamos a la página definida por el usuario
        if (is_null(filter_input(INPUT_GET, 'page'))) {
            $fsc->select_default_page();
        }

        if ($fsc->template) {
            $renderParams = [
                'fsc' => $fsc,
                'nlogin' => filter_input(INPUT_POST, 'user') ?? filter_input(INPUT_COOKIE, 'user') ?? '',
            ];

            // Use AJAX render mode for AJAX requests (returns content only, no header/footer)
            if ($fsc->isAjax()) {
                header('Content-Type: text/html; charset=UTF-8');
                header('X-Content-Type-Options: nosniff');
                echo Html::renderAjax($fsc->template, $renderParams);
            } else {
                echo Html::render($fsc->template, $renderParams);
            }
        }

        /// guardamos los errores en el log (los producidos durante la carga del template)
        $log_manager->save();

        /// cerramos las conexiones
        $fsc->close();
    }

    /**
     * Definir URL base del sistema si no está definida.
     */
    private static function defineBaseUrl(): void
    {
        if (defined('FS_BASE_URL')) {
            return;
        }

        $protocol = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            $protocol = 'https';
        }

        $host = 'localhost';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }

        $base_path = (defined('FS_PATH') && FS_PATH !== '') ? FS_PATH : '';
        if (empty($base_path) && !empty($_SERVER['SCRIPT_NAME'])) {
            $script_dir = dirname($_SERVER['SCRIPT_NAME']);
            if ($script_dir !== '/' && $script_dir !== '\\') {
                $base_path = $script_dir;
            }
        }

        define('FS_BASE_URL', $protocol . '://' . $host . $base_path);
    }
}
