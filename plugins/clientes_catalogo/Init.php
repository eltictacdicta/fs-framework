<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Plugins\clientes_catalogo;

require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php';

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;
use FSFramework\Event\TwigLoaderEvent;
use FSFramework\Plugins\clientes_core\ViewHookRegistry;
use Twig\Loader\FilesystemLoader;

class Init
{
    /**
     * @var bool
     */
    private static $hooksRegistered = false;

    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();

        $dispatcher->addListener(TwigLoaderEvent::NAME, function (TwigLoaderEvent $event) {
            $loader = $event->getLoader();
            if ($loader instanceof FilesystemLoader) {
                $loader->addPath(__DIR__ . '/View', 'clientes_catalogo');
            }
        });

        $dispatcher->addListener(TwigInitEvent::NAME, function (TwigInitEvent $event) {
            $this->registerHooks();
            $this->registerTwigGlobals($event->getTwig());
        });
    }

    private function registerHooks(): void
    {
        if (self::$hooksRegistered) {
            return;
        }

        ViewHookRegistry::register('cliente_form_after_main', '@clientes_catalogo/Hooks/cliente_form_catalogo.html.twig');
        ViewHookRegistry::register('cliente_direccion_form_after_codpais', '@clientes_catalogo/Hooks/direccion_form_catalogo.html.twig');
        self::$hooksRegistered = true;
    }

    private function registerTwigGlobals(\Twig\Environment $twig): void
    {
        $twig->addGlobal('clientes_catalogo_divisas', $this->loadDivisas());
        $twig->addGlobal('clientes_catalogo_paises', $this->loadPaises());
    }

    private function loadDivisas(): array
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/model/core/divisa.php';
        if (!class_exists('divisa', false) && file_exists($file)) {
            require_once $file;
        }

        if (!class_exists('divisa', false)) {
            return [];
        }

        $model = new \divisa();
        return $model->all();
    }

    private function loadPaises(): array
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/model/core/pais.php';
        if (!class_exists('pais', false) && file_exists($file)) {
            require_once $file;
        }

        if (!class_exists('pais', false)) {
            return [];
        }

        $model = new \pais();
        return $model->all();
    }
}