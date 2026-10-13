<?php

declare(strict_types=1);

namespace OneLogin\OidcCore\Jwks;

/** @api */
final class JwksDocument
{
    /**
     * @return array<int, array<string, mixed>>|null
     */
    public static function decodeKeys(string $json): ?array
    {
        $document = json_decode($json, true);

        return is_array($document) ? self::extractKeys($document) : null;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<int, array<string, mixed>>|null
     */
    public static function extractKeys(array $document): ?array
    {
        if (empty($document['keys']) || !is_array($document['keys'])) {
            return null;
        }

        foreach ($document['keys'] as $key) {
            if (!is_array($key)) {
                return null;
            }
        }

        return $document['keys'];
    }
}
