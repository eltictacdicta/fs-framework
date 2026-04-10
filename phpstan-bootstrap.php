<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/base/fs_functions.php';
require_once __DIR__ . '/base/fs_core_log.php';
require_once __DIR__ . '/base/fs_cache.php';
require_once __DIR__ . '/base/fs_db_engine.php';
require_once __DIR__ . '/base/fs_db2.php';
require_once __DIR__ . '/base/fs_ip_filter.php';
require_once __DIR__ . '/base/fs_login.php';
require_once __DIR__ . '/base/fs_model.php';
require_once __DIR__ . '/base/fs_prepared_db.php';
require_once __DIR__ . '/base/fs_query_builder.php';
require_once __DIR__ . '/base/fs_schema.php';
require_once __DIR__ . '/base/fs_model_autoloader.php';

$pluginDirs = array_filter(scandir(__DIR__ . '/plugins') ?: [], static function (string $entry): bool {
    if ($entry === '.' || $entry === '..') {
        return false;
    }

    return is_dir(__DIR__ . '/plugins/' . $entry) && !str_ends_with($entry, '_back');
});

$GLOBALS['plugins'] = array_values($pluginDirs);

fs_model_autoloader::register(false);
