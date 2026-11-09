<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core\Schema;

/**
 * The core schema steps every entry point (index.php, api.php, cron.php) runs
 * before serving: core-table self-heal, then the one-shot migrations.
 *
 * Once every step succeeds a stamp is written to tmp/, keyed by the core
 * VERSION and the database identity, and later requests skip the steps (no
 * list_tables(), no fs_var reads). A new VERSION, another database or deleting
 * the stamp makes the next request run them again. A failed step writes no
 * stamp, so the next request retries, as before.
 */
final class CoreSchemaBootstrap
{
    /** @var array<string, callable(): bool> */
    private array $steps;

    /**
     * @param array<string, callable(): bool>|null $steps step name => callable returning success
     */
    public function __construct(private string $stampFile, ?array $steps = null)
    {
        $this->steps = $steps ?? [
            'Core tables self-heal' => static function (): bool {
                $errors = \fs_schema::selfHealCoreTables()['errors'];
                if ($errors !== []) {
                    throw new \RuntimeException(implode('; ', $errors));
                }

                return true;
            },
            'AdminOnlyPagesMigration' => static fn (): bool => AdminOnlyPagesMigration::run(),
            'ContentI18nMigration' => static fn (): bool => ContentI18nMigration::run(),
        ];
    }

    /**
     * Runs the steps unless the current stamp exists.
     *
     * @param callable(string): void $logError receives one message per failed step
     */
    public static function ensure(callable $logError): void
    {
        (new self(self::defaultStampFile()))->run($logError);
    }

    /**
     * @param callable(string): void $logError
     * @return bool true when the schema is known good (stamp present or all steps succeeded)
     */
    public function run(callable $logError): bool
    {
        if (is_file($this->stampFile)) {
            return true;
        }

        $ok = true;
        foreach ($this->steps as $name => $step) {
            try {
                if (!$step()) {
                    $ok = false;
                    $logError($name . ' failed: step returned false');
                }
            } catch (\Throwable $e) {
                $ok = false;
                $logError($name . ' failed: ' . $e->getMessage());
            }
        }

        if ($ok && @file_put_contents($this->stampFile, date('c')) === false) {
            $logError('Core schema stamp could not be written: ' . $this->stampFile);
        }

        return $ok;
    }

    /**
     * Drops every core schema stamp so the next request re-runs the steps.
     * Call it after anything that replaces the database contents (backup
     * restore) or when the operator clears the caches.
     */
    public static function invalidate(): void
    {
        foreach (glob(self::stampPrefix() . '*.ok') ?: [] as $file) {
            @unlink($file);
        }
    }

    public static function defaultStampFile(): string
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        $version = is_file($folder . '/VERSION') ? trim((string) file_get_contents($folder . '/VERSION')) : '0';
        $db = implode('|', array_map(
            static fn (string $c): string => defined($c) ? (string) constant($c) : '',
            ['FS_DB_TYPE', 'FS_DB_HOST', 'FS_DB_PORT', 'FS_DB_NAME']
        ));

        return self::stampPrefix() . preg_replace('/[^A-Za-z0-9.]/', '_', $version) . '_' . substr(md5($db), 0, 12) . '.ok';
    }

    /** FS_TMP_NAME is a name prefix, not always a directory: it may lack the trailing slash. */
    private static function stampPrefix(): string
    {
        return (defined('FS_FOLDER') ? FS_FOLDER : '.') . '/tmp/'
            . (defined('FS_TMP_NAME') ? FS_TMP_NAME : '') . 'core_schema_';
    }
}
