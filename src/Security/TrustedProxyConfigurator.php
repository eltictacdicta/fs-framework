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

use Symfony\Component\HttpFoundation\Request;

/**
 * Applies FS_TRUSTED_PROXIES / FS_TRUSTED_HEADERS to Symfony Request once per request.
 */
final class TrustedProxyConfigurator
{
    private static bool $configured = false;

    public static function configure(): void
    {
        if (self::$configured) {
            return;
        }

        $proxies = self::resolveTrustedProxies();
        if ($proxies === []) {
            self::$configured = true;

            return;
        }

        $trustedHeaderSet = self::resolveTrustedHeaderSet();
        Request::setTrustedProxies($proxies, $trustedHeaderSet);

        if (method_exists(Request::class, 'setTrustedHeaders')) {
            Request::setTrustedHeaders($trustedHeaderSet);
        }

        self::$configured = true;
    }

    /**
     * @return list<string>
     */
    private static function resolveTrustedProxies(): array
    {
        $configured = defined('FS_TRUSTED_PROXIES') ? FS_TRUSTED_PROXIES : getenv('FS_TRUSTED_PROXIES');

        if (is_array($configured)) {
            $items = $configured;
        } elseif (is_string($configured) && $configured !== '') {
            $items = preg_split('/[\s,]+/', $configured) ?: [];
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            $proxy = trim((string) $item);
            if ($proxy !== '') {
                $result[] = $proxy;
            }
        }

        return array_values(array_unique($result));
    }

    private static function resolveTrustedHeaderSet(): int
    {
        $default = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX;

        $configured = defined('FS_TRUSTED_HEADERS') ? FS_TRUSTED_HEADERS : getenv('FS_TRUSTED_HEADERS');

        if (is_int($configured)) {
            return $configured;
        }

        if (!is_string($configured) || trim($configured) === '') {
            return $default;
        }

        $map = [
            'forwarded' => Request::HEADER_FORWARDED,
            'x_forwarded_for' => Request::HEADER_X_FORWARDED_FOR,
            'x_forwarded_host' => Request::HEADER_X_FORWARDED_HOST,
            'x_forwarded_proto' => Request::HEADER_X_FORWARDED_PROTO,
            'x_forwarded_port' => Request::HEADER_X_FORWARDED_PORT,
            'x_forwarded_prefix' => Request::HEADER_X_FORWARDED_PREFIX,
            'x_forwarded_aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'x_forwarded_traefik' => Request::HEADER_X_FORWARDED_TRAEFIK,
            'aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'traefik' => Request::HEADER_X_FORWARDED_TRAEFIK,
        ];

        $set = 0;
        $tokens = preg_split('/[\s,|]+/', strtolower($configured)) ?: [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (isset($map[$token])) {
                $set |= $map[$token];
            }
        }

        return $set > 0 ? $set : $default;
    }
}
