<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Build a libpq connection string. Dependency-free: shared by install.php
 * (runs before the autoloader) and the fs_postgresql driver.
 *
 * libpq keyword/value syntax: values are single-quoted and only `\` and `'`
 * are backslash-escaped. pg_escape_string() doubles quotes (SQL syntax), which
 * libpq does not understand, so passwords with spaces or quotes broke login.
 *
 * @param array<string, string|int|null> $params keyword => value; empty values are skipped
 */
function fs_pg_conninfo(array $params): string
{
    $parts = [];
    foreach ($params as $key => $value) {
        $value = (string) $value;
        if ($value === '') {
            continue;
        }
        $parts[] = $key . "='" . addcslashes($value, "'\\") . "'";
    }

    return implode(' ', $parts);
}
