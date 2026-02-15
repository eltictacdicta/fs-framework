<?php
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

use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\UriSigner;

class SignedUrlService
{
    private static ?UriSigner $signer = null;

    public static function sign(string $url, \DateTimeInterface|\DateInterval|int|null $expiration = null): string
    {
        $signer = self::getSigner();

        if ($expiration === null) {
            return $signer->sign($url);
        }

        return $signer->sign($url, $expiration);
    }

    public static function check(string $url): bool
    {
        try {
            return self::getSigner()->check($url);
        } catch (SignedUriException $e) {
            return false;
        }
    }

    private static function getSigner(): UriSigner
    {
        if (self::$signer === null) {
            self::$signer = new UriSigner(SecretManager::getSecret());
        }

        return self::$signer;
    }
}
