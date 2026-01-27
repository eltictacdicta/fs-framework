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

namespace FSFramework\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Twig\Loader\LoaderInterface;

/**
 * Event dispatched when initializing the Twig loader.
 * Plugins can listen to this event to wrap or replace the loader.
 * 
 * Usage in plugin Init.php:
 *   $dispatcher->addListener('view.twig_loader_init', function(TwigLoaderEvent $event) {
 *       $event->setLoader(new MyCustomLoader($event->getLoader()));
 *   });
 */
class TwigLoaderEvent extends Event
{
    public const NAME = 'view.twig_loader_init';

    private LoaderInterface $loader;

    public function __construct(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    public function getLoader(): LoaderInterface
    {
        return $this->loader;
    }

    public function setLoader(LoaderInterface $loader): void
    {
        $this->loader = $loader;
    }
}
