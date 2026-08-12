<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class PluginInstallProviderRegistry
{
    private static ?PluginInstallProvider $provider = null;

    private static bool $locked = false;

    public static function register(PluginInstallProvider $provider, bool $lock = true): void
    {
        self::$provider = $provider;
        self::$locked = $lock;
    }

    public static function reset(): void
    {
        self::$provider = null;
        self::$locked = false;
    }

    public static function isLocked(): bool
    {
        return self::$locked;
    }

    public static function get(): PluginInstallProvider
    {
        return self::$provider ?? new NullPluginInstallProvider();
    }
}
