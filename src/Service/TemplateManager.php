<?php

namespace FSFramework\Service;

use Twig\Environment;

/**
 * Gestor de plantillas que permite usar tanto RainTPL como Twig
 */
class TemplateManager
{
    private Environment $twig;
    private bool $useTwig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->useTwig = false;
    }

    /**
     * Establece si se debe usar Twig o RainTPL
     */
    public function setUseTwig(bool $useTwig): void
    {
        $this->useTwig = $useTwig;
    }

    /**
     * Renderiza una plantilla
     */
    public function render(string $template, array $variables = []): string
    {
        if ($this->useTwig) {
            return $this->renderTwig($template, $variables);
        }

        return $this->renderRainTPL($template, $variables);
    }

    /**
     * Renderiza una plantilla usando Twig
     */
    private function renderTwig(string $template, array $variables = []): string
    {
        // Añadimos la extensión .twig si no la tiene
        if (!str_ends_with($template, '.twig')) {
            $template .= '.twig';
        }

        return $this->twig->render($template, $variables);
    }

    /**
     * Renderiza una plantilla usando RainTPL
     */
    private function renderRainTPL(string $template, array $variables = []): string
    {
        // Creamos una instancia de RainTPL
        $rain = new \RainTPL();

        // Asignamos las variables
        foreach ($variables as $key => $value) {
            $rain->assign($key, $value);
        }

        // Renderizamos la plantilla
        return $rain->draw($template, true);
    }
}
