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

namespace Tests\Security;

use FSFramework\Security\CookieSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once FS_FOLDER . '/base/fs_db2.php';
require_once FS_FOLDER . '/base/fs_session_manager.php';

/**
 * El HMAC `auth_sig` es obligatorio en todos los caminos de restauración de
 * login por cookie.
 *
 * Antes de este contrato bastaba `user` + un `logkey` válido: la firma sólo se
 * comprobaba si estaba presente, así que omitir la cookie evitaba la
 * comprobación por completo (`/src/Security/LegacyAuthBridge.php` ya exigía la
 * firma; los otros tres caminos no).
 *
 * Se cubren aquí los dos caminos sin punto de inyección directo:
 *
 *  1. `FsSessionLegacyFallback::isRememberMeSignatureValid()`
 *     (base/fs_session_manager.php) — guardia de la sincronización de sesión
 *     legacy. Su invocador llama a `new fs_user()` y necesita base de datos, así
 *     que la guardia se ejerce por reflexión, en el nivel más bajo ejecutable.
 *
 *  2. `FSFramework\Core\StealthMode::validateLegacyCookies()`
 *     (src/Core/StealthMode.php) — acepta un doble de `fs_db2`, así que el
 *     método privado completo se ejecuta de verdad.
 *
 * El tercer camino (`fs_login::applyValidCookieLogin()`) se cubre en
 * tests/Base/FsLoginCharacterizationTest.php, que ya lo invocaba por reflexión.
 */
#[CoversClass(\FsSessionLegacyFallback::class)]
#[CoversClass(\FSFramework\Core\StealthMode::class)]
class RememberMeSignatureRequiredTest extends TestCase
{
    // =====================================================================
    // Camino 2: FsSessionLegacyFallback::isRememberMeSignatureValid()
    // =====================================================================

    #[Test]
    public function sessionFallbackRejectsMissingSignature(): void
    {
        $this->assertFalse(
            $this->invokeSessionFallbackGuard('demo-user', 'live-logkey', ''),
            'Una cookie sin auth_sig no puede restaurar la sesión legacy'
        );
    }

    #[Test]
    public function sessionFallbackAcceptsOnlyAValidSignature(): void
    {
        $signature = CookieSigner::signRememberMe('demo-user', 'live-logkey');

        $this->assertNotSame('', $signature);
        $this->assertTrue($this->invokeSessionFallbackGuard('demo-user', 'live-logkey', $signature));
        $this->assertFalse(
            $this->invokeSessionFallbackGuard('demo-user', 'live-logkey', 'forged-signature'),
            'Una firma que no corresponde al payload debe rechazarse'
        );
    }

    // =====================================================================
    // Camino 3: StealthMode::validateLegacyCookies()
    // =====================================================================

    #[Test]
    public function stealthModeRejectsMissingSignatureWithoutTouchingTheDatabase(): void
    {
        $db = $this->makeDbStub([['nick' => 'demo-user']]);
        $stealth = new \FSFramework\Core\StealthMode($db);

        $this->assertFalse(
            $this->invokeValidateLegacyCookies($stealth, 'demo-user', 'live-logkey', ''),
            'Un logkey vivo sin auth_sig no puede dar acceso en modo stealth'
        );
        $this->assertSame([], $db->queries, 'La ausencia de firma se rechaza antes de consultar la base de datos');
    }

    #[Test]
    public function stealthModeRejectsEmptyUserOrLogkey(): void
    {
        $db = $this->makeDbStub([['nick' => 'demo-user']]);
        $stealth = new \FSFramework\Core\StealthMode($db);

        $this->assertFalse($this->invokeValidateLegacyCookies($stealth, '', 'live-logkey', 'sig'));
        $this->assertFalse($this->invokeValidateLegacyCookies($stealth, 'demo-user', '', 'sig'));
        $this->assertSame([], $db->queries);
    }

    #[Test]
    public function stealthModeRejectsForgedSignatureWithoutTouchingTheDatabase(): void
    {
        $db = $this->makeDbStub([['nick' => 'demo-user']]);
        $stealth = new \FSFramework\Core\StealthMode($db);

        $this->assertFalse($this->invokeValidateLegacyCookies($stealth, 'demo-user', 'live-logkey', 'forged-signature'));
        $this->assertSame([], $db->queries);
    }

    #[Test]
    public function stealthModeRequiresTheLogkeyToStillExistInTheDatabase(): void
    {
        // Firma correcta, pero el logkey ya no está vigente en la base de datos.
        $db = $this->makeDbStub([]);
        $stealth = new \FSFramework\Core\StealthMode($db);

        $this->assertFalse(
            $this->invokeValidateLegacyCookies(
                $stealth,
                'demo-user',
                'live-logkey',
                CookieSigner::signRememberMe('demo-user', 'live-logkey')
            ),
            'Una firma válida no puede saltarse la comprobación del logkey en la base de datos'
        );
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('FROM fs_users', $db->queries[0]);
    }

    #[Test]
    public function stealthModeAcceptsValidSignatureAndLiveLogkey(): void
    {
        $db = $this->makeDbStub([['nick' => 'demo-user']]);
        $stealth = new \FSFramework\Core\StealthMode($db);

        $this->assertTrue(
            $this->invokeValidateLegacyCookies(
                $stealth,
                'demo-user',
                'live-logkey',
                CookieSigner::signRememberMe('demo-user', 'live-logkey')
            )
        );
    }

    /**
     * La guardia se ejerce por reflexión: su invocador real instancia `fs_user`
     * (base de datos) antes de llegar aquí.
     */
    private function invokeSessionFallbackGuard(string $nick, string $logkey, string $cookieSig): bool
    {
        $method = new ReflectionMethod(\FsSessionLegacyFallback::class, 'isRememberMeSignatureValid');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $nick, $logkey, $cookieSig);
    }

    private function invokeValidateLegacyCookies(
        \FSFramework\Core\StealthMode $stealth,
        string $user,
        string $logkey,
        string $authSig
    ): bool {
        $method = new ReflectionMethod(\FSFramework\Core\StealthMode::class, 'validateLegacyCookies');
        $method->setAccessible(true);

        return (bool) $method->invoke($stealth, $user, $logkey, $authSig);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function makeDbStub(array $rows): \fs_db2
    {
        return new class($rows) extends \fs_db2 {
            /** @var array<int, string> */
            public array $queries = [];

            /**
             * @param array<int, array<string, string>> $rows
             */
            public function __construct(private array $rows)
            {
            }

            public function connected(): bool
            {
                return true;
            }

            public function connect(): bool
            {
                return true;
            }

            public function escape_string($str): string
            {
                return addslashes((string) $str);
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function select($sql, $params = []): array
            {
                $this->queries[] = (string) $sql;

                return $this->rows;
            }
        };
    }
}
