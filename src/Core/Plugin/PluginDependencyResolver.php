<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class PluginDependencyResolver
{
    public function __construct(
        private readonly PluginInstallProvider $installProvider,
    ) {
    }

    /**
     * @return list<string> ordered activation plan (dependencies first, target last)
     */
    public function buildActivationOrder(string $targetPlugin): array
    {
        $targetPlugin = $this->normalizeName($targetPlugin);
        if ($targetPlugin === '') {
            return [];
        }

        $visited = [];
        $stack = [];
        $order = [];

        $this->visit($targetPlugin, $visited, $stack, $order);

        return $order;
    }

    /**
     * @param array<string, true> $visited
     * @param array<string, true> $stack
     * @param list<string> $order
     */
    private function visit(string $pluginName, array &$visited, array &$stack, array &$order): void
    {
        if (isset($stack[$pluginName])) {
            $path = array_keys($stack);
            $path[] = $pluginName;
            throw new CircularPluginDependencyException($path);
        }

        if (isset($visited[$pluginName])) {
            return;
        }

        $stack[$pluginName] = true;

        foreach ($this->installProvider->getDirectRequirements($pluginName) as $dependency) {
            $dependency = $this->normalizeName($dependency);
            if ($dependency === '') {
                continue;
            }

            $this->visit($dependency, $visited, $stack, $order);
        }

        unset($stack[$pluginName]);
        $visited[$pluginName] = true;
        $order[] = $pluginName;
    }

    private function normalizeName(string $pluginName): string
    {
        $normalized = basename(str_replace('\\', '/', trim($pluginName)));
        $normalized = preg_replace('/-(master|main)$/i', '', $normalized) ?? '';
        $normalized = preg_replace('/[^A-Za-z0-9_-]/', '', $normalized) ?? '';

        return trim($normalized);
    }
}
