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

        // Brace counting must ignore braces inside strings, comments and other
        // literals: a `{` in a message or a `'}'` in a string would otherwise
        // truncate or overrun the body. token_get_all() gives us the syntax.
        $tokens = token_get_all($source);
        $depth = 0;
        $started = false;
        $offset = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $length = strlen($text);

            // Only T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES and literal braces
            // count as structure; string/comment tokens are single units.
            if ($offset + $length > $open && ($started || $offset >= $open)) {
                if ($text === '{') {
                    $depth++;
                    $started = true;
                } elseif ($text === '}' && $started) {
                    $depth--;
                    if ($depth === 0) {
                        $start = $open;
                        $end = $offset + $length;

                        return substr($source, $start, $end - $start);
                    }
                }
            }

            $offset += $length;
        }

        Assert::fail(sprintf('Unbalanced braces while reading %s().', $method));
    }
}
