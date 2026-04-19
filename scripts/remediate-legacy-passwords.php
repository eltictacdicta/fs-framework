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

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde CLI.\n");
    exit(1);
}

define('FS_FOLDER', dirname(__DIR__));
chdir(FS_FOLDER);

if (!file_exists(FS_FOLDER . '/config.php')) {
    fwrite(STDERR, "No existe config.php. Configura primero la aplicación.\n");
    exit(1);
}

require_once FS_FOLDER . '/config.php';
require_once FS_FOLDER . '/base/fs_secret_migrator.php';
fs_secret_migrator::ensure();
require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_cache.php';
require_once FS_FOLDER . '/base/fs_db2.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/model/fs_user.php';

$options = getopt('', ['password-file:', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  ddev exec php scripts/remediate-legacy-passwords.php\n";
    echo "  ddev exec php scripts/remediate-legacy-passwords.php --password-file=/ruta/credenciales.csv\n\n";
    echo "El CSV debe tener dos columnas: nick,password\n";
    echo "Sin --password-file el script solo audita cuentas con hash SHA1 legacy.\n";
    echo "Con --password-file migra a Argon2ID solo las cuentas cuyo password en claro se haya proporcionado.\n";
    echo "Las cuentas restantes requieren reseteo de contraseña; no es posible rehashear SHA1 sin conocer la clave original.\n";
    exit(0);
}

$userModel = new fs_user();
$users = $userModel->all();
$legacyUsers = array_values(array_filter($users, static fn(fs_user $user): bool => $user->is_legacy_sha1_password()));

if (empty($legacyUsers)) {
    echo "No se han encontrado hashes SHA1 legacy pendientes.\n";
    exit(0);
}

echo "Cuentas con hash SHA1 legacy detectadas: " . count($legacyUsers) . "\n";
foreach ($legacyUsers as $user) {
    echo " - {$user->nick}";
    if (!empty($user->email)) {
        echo " <{$user->email}>";
    }
    echo "\n";
}

if (!isset($options['password-file'])) {
    echo "\nAuditoría completada. Para migrar cuentas concretas, proporcione --password-file con nick,password.\n";
    echo "Las cuentas que sigan en SHA1 deben pasar por reseteo de contraseña antes del release.\n";
    exit(2);
}

$passwordFile = $options['password-file'];
if (!is_string($passwordFile) || $passwordFile === '' || !is_readable($passwordFile)) {
    fwrite(STDERR, "No se puede leer el archivo indicado en --password-file.\n");
    exit(1);
}

$knownPasswords = [];
if (($handle = fopen($passwordFile, 'rb')) === false) {
    fwrite(STDERR, "No se ha podido abrir el archivo de credenciales.\n");
    exit(1);
}

while (($row = fgetcsv($handle)) !== false) {
    if (!isset($row[0], $row[1])) {
        continue;
    }

    $nick = trim((string) $row[0]);
    $password = (string) $row[1];
    if ($nick !== '' && $password !== '') {
        $knownPasswords[$nick] = $password;
    }
}
fclose($handle);

$migrated = 0;
$pending = [];

foreach ($legacyUsers as $user) {
    $plainPassword = $knownPasswords[$user->nick] ?? null;
    if ($plainPassword === null) {
        $pending[] = $user->nick;
        continue;
    }

    if ($user->password !== sha1($plainPassword)) {
        $pending[] = $user->nick;
        continue;
    }

    if (!$user->set_password($plainPassword) || !$user->save()) {
        $pending[] = $user->nick;
        continue;
    }

    $migrated++;
    echo "Migrado: {$user->nick}\n";
}

echo "\nMigradas correctamente: {$migrated}\n";
if (!empty($pending)) {
    echo "Pendientes de reseteo o credencial conocida: " . implode(', ', $pending) . "\n";
    exit(2);
}

exit(0);