<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Cache;

use FSFramework\Cache\CacheManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A Memcached that does not answer must not cost the probe timeout on every
 * request: after a failed probe CacheManager stops probing for a while.
 */
final class CacheManagerMemcachedProbeTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testAFailedProbeIsNotRetriedUntilTheMarkerExpires(): void
    {
        if (!class_exists('Memcached')) {
            self::markTestSkipped('ext-memcached is not installed.');
        }

        // A listening socket: any probe that is attempted succeeds.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        define('FS_CACHE_HOST', '127.0.0.1');
        define('FS_CACHE_PORT', $port);

        $marker = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'memcached_down';
        $probe = new \ReflectionMethod(CacheManager::class, 'tryConnectMemcached');
        $manager = CacheManager::getInstance();

        try {
            touch($marker);
            self::assertFalse($probe->invoke($manager), 'A fresh marker skips the probe even though the server answers.');

            touch($marker, time() - 60);
            clearstatcache();
            self::assertTrue($probe->invoke($manager), 'An expired marker probes again.');
            self::assertFileDoesNotExist($marker, 'A successful probe clears the marker.');

            fclose($server);
            self::assertFalse($probe->invoke($manager));
            self::assertFileExists($marker, 'A failed probe leaves the marker.');
        } finally {
            @unlink($marker);
        }
    }
}
