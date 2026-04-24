<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2024-2026 FSFramework Contributors
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

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Secure plugin downloader with integrity verification.
 * Uses Symfony HttpClient for robust HTTP handling and supports
 * SHA256 checksum verification for downloaded files.
 */
class fs_plugin_downloader
{
    private const DEFAULT_TIMEOUT = 60;
    private const MAX_REDIRECTS = 5;
    private const HASH_ALGORITHM = 'sha256';

    private static ?HttpClientInterface $httpClient = null;

    private static function getHttpClient(): HttpClientInterface
    {
        if (self::$httpClient === null) {
            self::$httpClient = HttpClient::create([
                'timeout' => self::DEFAULT_TIMEOUT,
                'max_redirects' => self::MAX_REDIRECTS,
            ]);
        }
        return self::$httpClient;
    }

    /**
     * Download a file with optional checksum verification.
     *
     * @param string $url URL to download
     * @param string $destination Local path to save the file
     * @param string|null $expectedHash Expected SHA256 hash (optional)
     * @param string|null $authToken Authorization token for private repos (optional)
     * @param int $timeout Download timeout in seconds
     * @return bool True on success
     */
    public static function download(
        string $url,
        string $destination,
        ?string $expectedHash = null,
        ?string $authToken = null,
        int $timeout = self::DEFAULT_TIMEOUT
    ): bool {
        $client = self::getHttpClient();

        $headers = [
            'User-Agent' => 'FSFramework-PluginDownloader/1.0',
        ];

        if ($authToken !== null && $authToken !== '') {
            $headers['Authorization'] = 'token ' . $authToken;
        }

        $tempFile = null;
        $handle = null;
        $downloadCompleted = false;

        try {
            self::assertAllowedDownloadUrl($url);

            $response = $client->request('GET', $url, [
                'headers' => $headers,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log("fs_plugin_downloader: HTTP $statusCode for $url");
                return false;
            }

            $tempFile = $destination . '.tmp.' . bin2hex(random_bytes(8));
            $handle = fopen($tempFile, 'wb');
            if ($handle === false) {
                error_log("fs_plugin_downloader: Cannot create temp file: $tempFile");
                return false;
            }

            $hashContext = $expectedHash !== null ? hash_init(self::HASH_ALGORITHM) : null;
            $bytesWritten = 0;

            try {
                foreach ($client->stream($response) as $chunk) {
                    $content = $chunk->getContent();
                    $written = fwrite($handle, $content);
                    if ($written === false) {
                        error_log("fs_plugin_downloader: Write error to $tempFile");
                        return false;
                    }
                    $bytesWritten += $written;

                    if ($hashContext !== null) {
                        hash_update($hashContext, $content);
                    }
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                if (!$downloadCompleted && is_string($tempFile) && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }

            if ($bytesWritten === 0) {
                error_log("fs_plugin_downloader: Empty download from $url");
                return false;
            }

            if ($expectedHash !== null) {
                $actualHash = hash_final($hashContext);
                if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
                    error_log("SECURITY: Hash mismatch for $url. Expected: $expectedHash, Got: $actualHash");
                    self::auditLog('hash_mismatch', $url, [
                        'expected' => $expectedHash,
                        'actual' => $actualHash,
                    ]);
                    return false;
                }
            }

            if (!rename($tempFile, $destination)) {
                error_log("fs_plugin_downloader: Cannot move temp file to $destination");
                return false;
            }

            $downloadCompleted = true;

            self::auditLog('download_success', $url, [
                'size' => $bytesWritten,
                'hash_verified' => $expectedHash !== null,
            ]);

            return true;

        } catch (TransportExceptionInterface $e) {
            error_log("fs_plugin_downloader: Transport error for $url: " . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log("fs_plugin_downloader: Unexpected error for $url: " . $e->getMessage());
            return false;
        }
    }

    private static function assertAllowedDownloadUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new \InvalidArgumentException('Only HTTPS plugin download URLs are allowed.');
        }
    }

    /**
     * Fetch JSON content from a URL with authentication.
     *
     * @param string $url URL to fetch
     * @param string|null $authToken Authorization token
     * @param int $timeout Request timeout
     * @return array|null Decoded JSON or null on error
     */
    public static function fetchJson(
        string $url,
        ?string $authToken = null,
        int $timeout = 30
    ): ?array {
        $client = self::getHttpClient();

        $headers = [
            'User-Agent' => 'FSFramework-PluginDownloader/1.0',
            'Accept' => 'application/json',
        ];

        if ($authToken !== null && $authToken !== '') {
            $headers['Authorization'] = 'token ' . $authToken;
        }

        try {
            $response = $client->request('GET', $url, [
                'headers' => $headers,
                'timeout' => $timeout,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $content = $response->getContent();
            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("fs_plugin_downloader: Invalid JSON from $url");
                return null;
            }

            return $decoded;

        } catch (\Throwable $e) {
            error_log("fs_plugin_downloader: Error fetching JSON from $url: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify integrity of an existing file.
     *
     * @param string $filePath Path to the file
     * @param string $expectedHash Expected SHA256 hash
     * @return bool True if hash matches
     */
    public static function verifyFileIntegrity(string $filePath, string $expectedHash): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        $actualHash = hash_file(self::HASH_ALGORITHM, $filePath);
        return hash_equals(strtolower($expectedHash), strtolower($actualHash));
    }

    /**
     * Calculate SHA256 hash of a file.
     *
     * @param string $filePath Path to the file
     * @return string|null Hash string or null on error
     */
    public static function calculateFileHash(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        return hash_file(self::HASH_ALGORITHM, $filePath);
    }

    /**
     * Log security-relevant download events.
     */
    private static function auditLog(string $event, string $url, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('c'),
            'event' => $event,
            'url' => $url,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'user' => $_SESSION['user']['nick'] ?? 'system',
            'context' => $context,
        ];

        $logDir = defined('FS_FOLDER') ? FS_FOLDER . '/tmp/' : sys_get_temp_dir() . '/';
        $logFile = $logDir . (defined('FS_TMP_NAME') ? FS_TMP_NAME : '') . 'download_audit.log';

        @file_put_contents(
            $logFile,
            json_encode($logEntry) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
