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

class PrivacyMasker
{
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        return substr($local, 0, 2) . '***@' . substr($domain, 0, 3) . '***';
    }

    public static function maskCodcliente(string $codcliente): string
    {
        if (strlen($codcliente) <= 2) {
            return '***';
        }
        return substr($codcliente, 0, 2) . '****';
    }

    public static function maskInfrico(string $infrico): string
    {
        if (strlen($infrico) < 4) {
            return '***';
        }
        return substr($infrico, 0, 2) . '*****';
    }

    public static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.***';
        }
        return substr($ip, 0, 8) . '***';
    }
}
