<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Runs the plugin PHPStan profile against the plugins that are actually
 * installed.
 *
 * Plugins live in their own repositories and are gitignored by the core, so a
 * checkout can legitimately lack any of them. PHPStan aborts the whole run when
 * a `scanDirectories` entry does not exist ("Scanned directory ... does not
 * exist"), which would make the gate unusable on a partial install.
 *
 * This wrapper resolves the analysed paths and the scanned directories from
 * what is present on disk, writes a temporary profile that includes
 * `phpstan-plugins.neon`, and runs PHPStan with it.
 *
 * Usage:
 *   ddev exec composer phpstan:plugins
 *   ddev exec php tools/phpstan-plugins.php --error-format=json
 */

$root = dirname(__DIR__);
$phpstan = $root . '/vendor/dev-tools/bin/phpstan';

if (!is_file($phpstan)) {
    fwrite(STDERR, "PHPStan is not installed. Run:\n");
    fwrite(STDERR, "  ddev exec composer install --working-dir=dev-tools\n");
    exit(1);
}

/**
 * Directories analysed for findings. Each entry must exist to be analysed.
 */
$analysed = [
    'plugins/clientes_core/ClienteForm.php',
    'plugins/clientes_core/Init.php',
    'plugins/clientes_core/model',
    'plugins/tpvmod/lib',
    'plugins/tpvmod/Init.php',
];

/**
 * Directories indexed for symbol resolution only. Sibling plugins are optional
 * by design; the ones missing on this checkout are simply skipped.
 */
$scanned = [
    'base',
    'src',
    'plugins/catalogo_core',
    'plugins/business_data',
    'plugins/clientes_core',
    'plugins/clientes_facturacion',
    'plugins/factura_pdf1',
];

$present = static function (array $candidates) use ($root): array {
    $resolved = [];
    foreach ($candidates as $candidate) {
        $absolute = $root . '/' . $candidate;
        if (file_exists($absolute)) {
            $resolved[] = $absolute;
        }
    }

    return $resolved;
};

$paths = $present($analysed);
$scanDirectories = $present($scanned);

if ($paths === []) {
    fwrite(STDERR, "No plugin paths to analyse: none of the configured plugins is installed.\n");
    exit(1);
}

$profile = $root . '/phpstan-plugins.neon';

$temporary = sys_get_temp_dir() . '/phpstan-plugins-' . bin2hex(random_bytes(8)) . '.neon';
if (file_put_contents($temporary, '') === false) {
    fwrite(STDERR, "Could not create a temporary profile.\n");
    exit(1);
}

$yamlList = static function (array $items): string {
    return implode("\n", array_map(static fn (string $item): string => '        - ' . $item, $items));
};

$contents = "includes:\n"
    . '    - ' . $profile . "\n"
    . "parameters:\n"
    . "    scanDirectories:\n" . $yamlList($scanDirectories) . "\n"
    . "    paths:\n" . $yamlList($paths) . "\n";

file_put_contents($temporary, $contents);

$extra = array_slice($argv, 1);
$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($phpstan)
    . ' analyse'
    . ' -c ' . escapeshellarg($temporary)
    . ' --memory-limit=1G';

foreach ($extra as $argument) {
    $command .= ' ' . escapeshellarg($argument);
}

passthru($command, $exitCode);
unlink($temporary);

exit($exitCode);
