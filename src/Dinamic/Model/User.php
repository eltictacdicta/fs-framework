<?php

declare(strict_types=1);

namespace FSFramework\Dinamic\Model;

/**
 * Legacy-compatible facade over the authenticated fs_user.
 *
 * This class mirrors the FS2025 "current user" model, but it is NOT a source
 * of authority by itself. Identity is taken from the authenticated session and
 * the admin flag is read from the database row, so a forged or stale client
 * value can never grant privileges:
 *
 * - It never trusts the unsigned `fsNick` cookie for identity.
 * - `admin` defaults to FALSE and only becomes TRUE when an enabled fs_user
 *   loaded from the database says so.
 * - Any failure while resolving the user fails closed (anonymous non-admin).
 */
class User
{
    public $idempresa;
    public $nick;
    public bool $admin = false;
    public $fs_user_legacy;

    public function __construct()
    {
        try {
            $nick = $this->resolveAuthenticatedNick();
            if ($nick === null) {
                // No authenticated session: remain an anonymous non-admin.
                return;
            }

            $this->nick = $nick;
            $this->loadLegacyUser($nick);
        } catch (\Throwable) {
            // Fail closed: never leave a partially elevated state behind.
            $this->admin = false;
            $this->fs_user_legacy = null;
        }
    }

    /**
     * Resolves the nick from the server-side session, never from client input.
     */
    private function resolveAuthenticatedNick(): ?string
    {
        $this->requireLegacyClass('fs_session_manager', '/base/fs_session_manager.php');

        if (!class_exists('fs_session_manager')) {
            return null;
        }

        $nick = \fs_session_manager::getCurrentUserNick();

        return is_string($nick) && trim($nick) !== '' ? $nick : null;
    }

    /**
     * Loads the fs_user row and derives the admin flag from the database.
     */
    private function loadLegacyUser(string $nick): void
    {
        $this->requireLegacyClass('fs_user', '/model/core/fs_user.php');

        if (!class_exists('fs_user')) {
            return;
        }

        $loaded = (new \fs_user())->get($nick);
        if (!$loaded || empty($loaded->enabled)) {
            return;
        }

        $this->fs_user_legacy = $loaded;
        $this->admin = (bool) $loaded->admin;
    }

    private function requireLegacyClass(string $class, string $relativePath): void
    {
        if (class_exists($class)) {
            return;
        }

        $folder = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 3);
        $path = $folder . $relativePath;

        if (file_exists($path)) {
            require_once $path;
        }
    }

    public function __get($name)
    {
        if (isset($this->fs_user_legacy) && isset($this->fs_user_legacy->$name)) {
            return $this->fs_user_legacy->$name;
        }
        return null;
    }

    public function __call($name, $arguments)
    {
        if (isset($this->fs_user_legacy) && method_exists($this->fs_user_legacy, $name)) {
            return call_user_func_array([$this->fs_user_legacy, $name], $arguments);
        }
        return null;
    }
}
