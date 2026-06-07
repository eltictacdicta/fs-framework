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
 * Detects whether the current request should emit Secure cookies.
 *
 * Aligns session cookie flags with Symfony Request::isSecure(), including
 * trusted reverse-proxy headers when FS_TRUSTED_PROXIES is configured.
 */
final class SecureRequestDetector
{
    public static function isSecure(): bool
    {
        TrustedProxyConfigurator::configure();

        // No usamos Request::createFromGlobals() porque hidrata $_FILES y
        // revienta con FileNotFoundException cuando un tmp_name apunta a un
        // archivo borrado (caso real: upload interrumpido). isSecure() solo
        // necesita $_SERVER + state de trusted proxies.
        return Request::create('', 'GET', [], [], [], $_SERVER)->isSecure();
    }
}
