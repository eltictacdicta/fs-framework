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

namespace FSFramework\Security;

use fs_user;

/**
 * Resolución y permisos de usuarios legacy basados en fs_user.
 */
final class LegacyUserService
{
    /**
     * @return object|null
     */
    public function findByNick(?string $nick)
    {
        if ($nick === null || $nick === '') {
            return null;
        }

        $this->loadDependencies();
        if (!class_exists('fs_user')) {
            return null;
        }

        $userModel = new fs_user();
        $user = $userModel->get($nick);
        return $user ?: null;
    }

    /**
     * @return object|null
     */
    public function findEnabledByNick(string $nick)
    {
        $user = $this->findByNick($nick);
        return $user && $user->enabled ? $user : null;
    }

    /**
     * @param object|null $user
     */
    public function canAccess($user, string $pageName): bool
    {
        if (!is_object($user)) {
            return false;
        }

        if ((bool) ($user->admin ?? false)) {
            return true;
        }

        return method_exists($user, 'have_access_to')
            && $user->have_access_to($pageName);
    }

    /**
     * @param object|null $user
     */
    public function canDelete($user, string $pageName): bool
    {
        if (!is_object($user)) {
            return false;
        }

        if (method_exists($user, 'allow_delete_on')) {
            return $user->allow_delete_on($pageName);
        }

        return (bool) ($user->admin ?? false);
    }

    private function loadDependencies(): void
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        $dependencies = [
            'fs_cache' => $folder . '/base/fs_cache.php',
            'fs_core_log' => $folder . '/base/fs_core_log.php',
            'fs_db2' => $folder . '/base/fs_db2.php',
            'fs_model' => $folder . '/base/fs_model.php',
            'fs_page' => $folder . '/model/fs_page.php',
            'fs_access' => $folder . '/model/fs_access.php',
            'fs_user' => $folder . '/model/core/fs_user.php',
        ];

        foreach ($dependencies as $class => $path) {
            if (!class_exists($class) && file_exists($path)) {
                require_once $path;
            }
        }
    }
}
