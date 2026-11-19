<?php

declare(strict_types=1);

namespace Tests\Support;

use FSFramework\Security\RememberMeTokens;
use FSFramework\Security\RememberMeTokenStore;

/**
 * Almacén de tokens en memoria, con las reglas de fs_user_token salvo la
 * caducidad por tiempo: un token vale para su nick mientras no cambie la
 * huella de la contraseña (keyRef) ni se revoque.
 */
final class InMemoryRememberMeTokenStore implements RememberMeTokenStore
{
    /** @var array<string, array{nick: string, keyRef: string, user_agent: string, ip: string, created_at: int, last_used: int}> hash => fila */
    public array $rows = [];

    public function issue(string $nick, string $keyRef, string $userAgent, string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        $this->rows[RememberMeTokens::hashOf($token)] = ['nick' => $nick, 'keyRef' => $keyRef, 'user_agent' => $userAgent,
            'ip' => $ip, 'created_at' => time(), 'last_used' => time()];

        return $token;
    }

    public function isValid(string $nick, string $keyRef, string $token): bool
    {
        $row = $this->rows[RememberMeTokens::hashOf($token)] ?? null;

        return $row !== null && $row['nick'] === $nick && hash_equals($row['keyRef'], $keyRef);
    }

    public function isLive(string $nick, string $token): bool
    {
        return ($this->rows[RememberMeTokens::hashOf($token)]['nick'] ?? null) === $nick;
    }

    public function revoke(string $nick, string $tokenHash): bool
    {
        if (($this->rows[$tokenHash]['nick'] ?? null) === $nick) {
            unset($this->rows[$tokenHash]);
        }

        return true;
    }

    public function revokeAll(string $nick): bool
    {
        $this->rows = array_filter($this->rows, static fn(array $r) => $r['nick'] !== $nick);

        return true;
    }

    public function active(?string $nick): array
    {
        $out = [];
        foreach ($this->rows as $hash => $r) {
            if ($nick === null || $r['nick'] === $nick) {
                $out[] = ['token_hash' => $hash, 'nick' => $r['nick'], 'user_agent' => $r['user_agent'], 'ip' => $r['ip'],
                    'created_at' => $r['created_at'], 'last_used' => $r['last_used']];
            }
        }

        return $out;
    }
}
