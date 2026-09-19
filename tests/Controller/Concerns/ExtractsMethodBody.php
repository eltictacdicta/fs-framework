<?php
declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Controller\Concerns;

use PHPUnit\Framework\Assert;

/**
 * Extracts a method body from PHP source by brace-matching from its opening `{`.
 *
 * Shared by the controller invariant tests, which analyse controller source
 * statically: instantiating fs_controller boots a database connection plus
 * user, menu, extensions and plugins, so behaviour tests are not practical.
 */
trait ExtractsMethodBody
{
    private function methodBody(string $source, string $method): string
    {
        $signature = strpos($source, 'function ' . $method . '(');
        if ($signature === false) {
            Assert::fail(sprintf('Method %s() not found in source.', $method));
        }

        $open = strpos($source, '{', $signature);
        if ($open === false) {
            Assert::fail(sprintf('Opening brace for %s() not found.', $method));
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        Assert::fail(sprintf('Unbalanced braces while reading %s().', $method));
    }
}
