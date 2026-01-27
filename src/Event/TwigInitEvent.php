<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 */

namespace FSFramework\Event;

use Twig\Environment;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when Twig environment is initialized.
 * Allows plugins to register Twig functions, filters, and extensions.
 */
class TwigInitEvent extends Event
{
    public const NAME = 'twig.init';

    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
