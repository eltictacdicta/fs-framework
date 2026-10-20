<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

declare(strict_types=1);

/**
 * Genera el hash de una contraseña de usuario y la sentencia UPDATE lista para
 * pegar en phpMyAdmin o psql.
 *
 * No necesita base de datos, config.php ni la aplicación: el hash es portable,
 * así que se puede generar en cualquier máquina con PHP 8.2+. Eso evita subir
 * un PHP ejecutable al docroot del servidor, que era el riesgo del antiguo
 * emergency_admin_reset.php.
 *
 * La contraseña se lee por STDIN (oculta si hay TTY) y no por argv: como
 * argumento quedaría en el historial del shell y visible en `ps`.
 *
 * Uso:
 *   php scripts/reset-admin-password.php [nick]
 *   echo 'MiClaveNueva2026' | php scripts/reset-admin-password.php admin
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$args = array_slice($argv, 1);
if ($args !== [] && in_array($args[0], ['-h', '--help'], true)) {
    fwrite(STDOUT, <<<TXT
Uso: php scripts/reset-admin-password.php [nick]

Pide la contraseña por teclado sin mostrarla y escribe en pantalla:
  1. el hash Argon2id
  2. el UPDATE listo para pegar en phpMyAdmin o psql

Para automatización:  echo 'MiClave' | php scripts/reset-admin-password.php admin

El nick por defecto es "admin".
TXT . "\n");
    exit(0);
}

$nick = trim((string) ($args[0] ?? 'admin'));
if ($nick === '') {
    fwrite(STDERR, "El nick no puede estar vacío.\n");
    exit(1);
}

$password = fs_read_password();

if ($password === '') {
    fwrite(STDERR, "No se recibió ninguna contraseña.\n");
    exit(1);
}

$length = mb_strlen($password);
if ($length < 8 || $length > 32) {
    fwrite(STDERR, "La contraseña debe tener entre 8 y 32 caracteres (largo recibido: {$length}).\n");
    exit(1);
}

if ($length < 12) {
    fwrite(STDERR, "Aviso: menos de 12 caracteres. El instalador exige 12 como mínimo para cuentas nuevas.\n");
}

if (!defined('PASSWORD_ARGON2ID')) {
    fwrite(STDERR, "Esta instalación de PHP no soporta Argon2id; se usará bcrypt.\n");
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $algorithm = 'bcrypt';
} else {
    $hash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
    $algorithm = 'Argon2id';
}

if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "No se pudo generar el hash.\n");
    exit(1);
}

// La columna fs_users.password es varchar(100): un hash Argon2id ronda los 97.
if (strlen($hash) > 100) {
    fwrite(STDERR, "El hash generado mide " . strlen($hash) . " caracteres y la columna fs_users.password es varchar(100).\n");
    exit(1);
}

$escapedNick = str_replace("'", "''", $nick);

fwrite(STDOUT, "\nHash {$algorithm} (" . strlen($hash) . " caracteres):\n");
fwrite(STDOUT, $hash . "\n\n");
fwrite(STDOUT, "Sentencia para phpMyAdmin / psql:\n");
fwrite(STDOUT, "UPDATE fs_users SET password = " . var_export($hash, true) . " WHERE nick = '" . $escapedNick . "';\n\n");
fwrite(STDOUT, "Aplicada la sentencia, iniciá sesión y cambiá la contraseña desde la aplicación.\n");

/**
 * Lee la contraseña de STDIN. Con TTY la pide sin eco, para que no quede en el
 * historial del shell ni en la lista de procesos.
 */
function fs_read_password(): string
{
    $isTty = function_exists('stream_isatty') && @stream_isatty(STDIN);

    if (!$isTty) {
        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    fwrite(STDOUT, 'Contraseña: ');

    $sttyState = @shell_exec('stty -g 2>/dev/null');
    $hidden = is_string($sttyState) && trim($sttyState) !== '';

    if ($hidden) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($hidden) {
        @shell_exec('stty ' . escapeshellarg(trim($sttyState)) . ' 2>/dev/null');
    }

    fwrite(STDOUT, "\n");

    return $line === false ? '' : rtrim($line, "\r\n");
}
