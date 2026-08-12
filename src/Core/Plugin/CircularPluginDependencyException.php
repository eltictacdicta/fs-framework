<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class CircularPluginDependencyException extends \RuntimeException
{
    /**
     * @param list<string> $path
     */
    public function __construct(
        private readonly array $path,
    ) {
        parent::__construct('Dependencia circular detectada: ' . implode(' → ', $path));
    }

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        return $this->path;
    }
}
