<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025-2026 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Core;

/**
 * Transport-only utility for Server-Sent Events (SSE) + progress-file I/O.
 *
 * Designed for plugin entry points that run long batch operations
 * (file copy, record insertion, etc.) and want to stream progress to
 * the browser via `EventSource`.
 *
 * Responsibilities (transport only):
 *  - Set SSE response headers.
 *  - Configure PHP for long-lived streaming (time limit, abort, output
 *    buffering, compression).
 *  - Emit SSE events and keepalive comments.
 *  - Persist a small progress snapshot to a JSON file under
 *    `sys_get_temp_dir()` with `flock()` protection so a sibling
 *    polling endpoint can read it without race conditions.
 *  - Garbage-collect old progress files.
 *
 * Non-responsibilities (caller's job):
 *  - **CSRF** — the entry point must validate via
 *    `FSFramework\Security\CsrfManager` BEFORE calling
 *    {@see ProgressStream::init()}. The utility does not own session
 *    state and never reads `$_GET` / `$_POST` / `$_COOKIE`.
 *  - **Authentication / authorization** — same; the entry point handles
 *    the session check.
 *  - **Cancellation / resume** — the progress file is best-effort; if
 *    the connection drops, the file is readable via a polling endpoint
 *    and the operator can re-import.
 *  - **Schema of the payload** — `saveProgress` writes
 *    `{step, message, percent, timestamp, error}` and nothing else. Do
 *    not store sensitive data here; the file lives in the system temp
 *    dir and is world-readable on a misconfigured host.
 *
 * @see plugins/system_updater/lib/process_bootstrap.php The original
 *      inline helpers this utility centralizes. Migration of that
 *      plugin to this utility is a separate-repo follow-up.
 */
final class ProgressStream
{
    /**
     * Default TTL for {@see ProgressStream::cleanupExpired()}: 24h.
     */
    public const DEFAULT_TTL_SECONDS = 86400;

    /**
     * Initialise the SSE response and compute the progress-file path.
     *
     * Side effects:
     *  - Sets SSE response headers (Content-Type, Cache-Control, etc.).
     *  - Configures PHP for long-lived streaming
     *    (`set_time_limit(0)`, `ignore_user_abort(true)`,
     *    `output_buffering=Off`, `implicit_flush=On`,
     *    `zlib.output_compression=Off`).
     *  - Drops any pre-existing output buffer.
     *
     * The `$sessionId` is sanitized via `preg_replace('/[^A-Za-z0-9_.-]/', '', ...)`
     * to prevent path traversal: callers MUST pass a trustworthy
     * identifier (e.g. `session_id()` or a CSRF token), NOT raw user
     * input. The function never reads `$_SESSION` itself — it only
     * sanitizes what the caller hands it.
     *
     * @param string $progressPrefix File-name prefix; gets sanitized
     *                               the same way as `$sessionId`.
     * @param string $sessionId      Trustworthy session-scoped identifier.
     * @return array{progress_file: string, session_id: string}
     */
    public static function init(string $progressPrefix, string $sessionId): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('output_handler', '');
        @ini_set('implicit_flush', 'On');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        // Drop any pre-existing output buffer (Plesk/FastCGI may auto-start
        // one). Skipped in CLI to avoid PHPUnit's "closed output buffers other
        // than its own" risky-test detection; CLI never needs this cleanup.
        if (PHP_SAPI !== 'cli' && ob_get_level() > 0) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header('Content-Encoding: identity');
            header('X-Content-Type-Options: nosniff');
        }

        $sanitizedPrefix = (string) preg_replace('/[^A-Za-z0-9_.-]/', '', $progressPrefix);
        $sanitizedSession = (string) preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId);

        $progressFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $sanitizedPrefix
            . '_'
            . $sanitizedSession
            . '.json';

        return [
            'progress_file' => $progressFile,
            'session_id' => $sanitizedSession,
        ];
    }

    /**
     * Emit a typed SSE event to the connected client.
     *
     * Format: `event: <name>\ndata: <json>\n\n`. Flushes the output
     * buffer + PHP's internal buffer so the event reaches the browser
     * immediately. Failures from `ob_flush()` / `flush()` are
     * intentionally suppressed — the connection may have been closed
     * by the peer (which is fine, `ignore_user_abort(true)`).
     *
     * @param string              $event Event name (`progress`, `complete`, etc.).
     * @param array<string,mixed> $data  Payload; encoded with
     *                                   `JSON_UNESCAPED_UNICODE`.
     * @return void
     */
    public static function sendEvent(string $event, array $data, bool $autoFlush = true): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // Flush the current buffer to the next layer (if any), then push
        // the accumulated output to the web server. `ob_flush()` does NOT
        // decrease the nesting level, so we must NOT loop on it — that
        // would be an infinite loop. The intent is to push the SSE event
        // to the client immediately, not to walk every output layer.
        // (Note: the same bug exists in the system_updater code we are
        // replacing; it is not reproducible in production because the
        // connection drops first.)
        //
        // `$autoFlush` is exposed so unit tests can capture the output
        // with PHPUnit's `expectOutputRegex` without racing the flush.
        // Production callers should leave it at the default `true`.
        if (!$autoFlush) {
            return;
        }
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Emit an SSE keepalive comment (`: keepalive <ts>\n\n`).
     *
     * SSE comment lines are ignored by browsers, but they keep
     * proxies, load balancers, and FastCGI from killing the
     * connection during long silent batches.
     *
     * @return void
     */
    public static function sendKeepalive(bool $autoFlush = true): void
    {
        echo ": keepalive " . time() . "\n\n";

        // See {@see sendEvent()} for the rationale. `$autoFlush` lets
        // unit tests capture the output without racing the flush.
        if (!$autoFlush) {
            return;
        }
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Persist a progress snapshot with `flock(LOCK_EX)` to prevent
     * torn writes when the SSE writer and a polling reader touch the
     * same file concurrently.
     *
     * The file is opened with mode `c` (create if missing, do not
     * truncate) and truncated AFTER the lock is held; this is the
     * pattern that keeps concurrent writers + readers safe.
     *
     * @param string      $progressFile Absolute path (typically
     *                                  `sys_get_temp_dir() . '/<prefix>_<session>.json'`).
     * @param string      $step         Short label (`reading`, `apply`, etc.).
     * @param string      $message      Human-readable status.
     * @param int         $percent      0–100.
     * @param string|null $error        Optional error message; `null` when OK.
     * @return array{step: string, message: string, percent: int, timestamp: int, error: string|null}
     */
    public static function saveProgress(
        string $progressFile,
        string $step,
        string $message,
        int $percent,
        ?string $error = null
    ): array {
        $data = [
            'step' => $step,
            'message' => $message,
            'percent' => $percent,
            'timestamp' => time(),
            'error' => $error,
        ];

        $fp = @fopen($progressFile, 'c');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        return $data;
    }

    /**
     * Read the current progress snapshot with a shared lock so the
     * writer is not blocked. Returns `null` if the file does not exist
     * or contains invalid JSON (the writer may be in the middle of a
     * `ftruncate` + `fwrite`; a retry on the next poll is fine).
     *
     * @param string $progressFile Absolute path.
     * @return array{step: string, message: string, percent: int, timestamp: int, error: string|null}|null
     */
    public static function readProgress(string $progressFile): ?array
    {
        if (!is_file($progressFile)) {
            return null;
        }

        $fp = @fopen($progressFile, 'rb');
        if ($fp === false) {
            return null;
        }

        $contents = '';
        if (@flock($fp, LOCK_SH)) {
            $contents = (string) fread($fp, 8192);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        if ($contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Delete a progress file. No-op if the file does not exist.
     *
     * @param string $progressFile Absolute path.
     * @return void
     */
    public static function cleanup(string $progressFile): void
    {
        if (is_file($progressFile)) {
            @unlink($progressFile);
        }
    }

    /**
     * Garbage-collect progress files older than `$ttlSeconds`.
     *
     * Intended to be called from a plugin's `Init.php` (boot-time
     * sweep) so abandoned progress files do not fill the temp
     * directory when SSE connections drop without cleanup.
     *
     * @param string $progressPrefix Same prefix used in {@see init()}.
     * @param int    $ttlSeconds     Age threshold; files with
     *                               `time() - filemtime($f) > $ttlSeconds`
     *                               are removed. Pass `0` to remove
     *                               every matching file.
     * @return int Number of files removed.
     */
    public static function cleanupExpired(string $progressPrefix, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): int
    {
        $sanitizedPrefix = (string) preg_replace('/[^A-Za-z0-9_.-]/', '', $progressPrefix);
        $pattern = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $sanitizedPrefix
            . '_*.json';

        $files = (array) glob($pattern);
        $now = time();
        $removed = 0;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = filemtime($file);
            if ($mtime === false) {
                continue;
            }
            if (($now - $mtime) > $ttlSeconds) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
