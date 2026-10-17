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

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regresión: la cookie PHPSESSID de OTRA aplicación del mismo host no puede
 * quedarse con la sesión de esta instalación.
 *
 * Cuando eso pasaba, la sesión propia (FSSESS_*) arrancaba con el ID ajeno, la
 * aplicación ignoraba su propia cookie y cada petición creaba una sesión nueva.
 * Consecuencia real en producción: el `oidc_sso_state` que guarda
 * `auth/init-sso.php` no existía cuando volvía `auth/callback.php`, y el SSO web
 * respondía "Token de seguridad inválido. Inicie sesión de nuevo." (HTTP 403).
 */
#[CoversClass(SessionManager::class)]
class SessionLegacyPhpSessionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $this->closeActiveSession();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->closeActiveSession();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * Las dos cookies del caso real: la PHPSESSID de otra app del host (path=/)
     * y la propia de esta instalación (path=/fsf/).
     *
     * Tras la migración, lo que queda activo tiene que ser SIEMPRE la sesión
     * propia: es la que guarda el `oidc_sso_state` que después lee callback.php.
     */
    #[Test]
    public function legacyPhpSessionCookieDoesNotHijackOwnSession(): void
    {
        $sessionName = SessionManager::resolveSessionName();
        $ownSessionId = '3vjhh21l048tv1b56pp7ugo25q';

        $_COOKIE = [
            $sessionName => $ownSessionId,
            'PHPSESSID' => 'legacyid000000000000000000',
        ];

        $legacyData = $this->runLegacyMigration();

        self::assertSame(
            $sessionName,
            session_name(),
            'La migración legacy debe devolverle el nombre de sesión a esta instalación'
        );
        self::assertSame(
            $ownSessionId,
            session_id(),
            'La migración legacy no puede dejar activo el ID de la cookie ajena'
        );
        self::assertSame([], $legacyData);
        self::assertArrayNotHasKey(
            'PHPSESSID',
            $_COOKIE,
            'La cookie legacy no debe seguir disponible para el resto de la petición'
        );
    }

    /**
     * Sin cookie propia, la migración legacy no aporta ningún ID: la sesión
     * propia se resuelve después (o se crea nueva).
     */
    #[Test]
    public function legacyMigrationLeavesNoSessionIdWhenThereIsNoOwnCookie(): void
    {
        $_COOKIE = ['PHPSESSID' => 'legacyid000000000000000000'];

        $this->runLegacyMigration();

        self::assertSame(
            SessionManager::resolveSessionName(),
            session_name(),
            'Aun sin cookie propia, el nombre de sesión vuelve a ser el de esta instalación'
        );
        self::assertSame(
            '',
            session_id(),
            'Sin cookie propia no se reutiliza el ID legacy: PHP resolvió más tarde'
        );
    }

    /**
     * El ID legacy nunca se reutiliza, ni siquiera cuando el mensaje de sesión
     * propia no existe.
     */
    #[Test]
    public function ownSessionIdIsNeverTakenFromTheLegacyCookie(): void
    {
        self::assertSame(
            '',
            SessionManager::resolveOwnSessionId(['PHPSESSID' => 'legacyid000000000000000000'], 'FSSESS_app'),
            'Sin cookie propia no hay ID propio: se deja que PHP lo resuelva'
        );

        self::assertSame(
            '',
            SessionManager::resolveOwnSessionId(
                ['PHPSESSID' => 'legacyid000000000000000000', 'FSSESS_app' => 'corta'],
                'FSSESS_app'
            ),
            'Un valor con formato inválido no se usa como ID de sesión'
        );

        $ownId = 'kh3f9ab12c4d5e6f7a8b9c0d';
        self::assertSame(
            $ownId,
            SessionManager::resolveOwnSessionId(
                ['PHPSESSID' => 'legacyid000000000000000000', 'FSSESS_app' => $ownId],
                'FSSESS_app'
            ),
            'Sólo la cookie propia aporta el ID de la sesión propia'
        );
    }

    /**
     * Contrato de rutas de borrado de la cookie legacy: se borra lo que puede
     * ser de esta instalación (las dos variantes del path propio) y no se toca
     * la cookie de la raíz cuando la aplicación vive en un subdirectorio,
     * porque puede pertenecer a otra aplicación del mismo host.
     */
    #[Test]
    public function legacyCookieIsClearedOnlyOnPathsThatCanBeItsOwn(): void
    {
        self::assertSame(['/'], SessionManager::legacyPhpSessionCookiePaths('/'));
        self::assertSame(['/fsf/', '/fsf'], SessionManager::legacyPhpSessionCookiePaths('/fsf/'));
        self::assertSame(['/fsf/', '/fsf'], SessionManager::legacyPhpSessionCookiePaths('/fsf'));
        self::assertNotContains(
            '/',
            SessionManager::legacyPhpSessionCookiePaths('/fsf/'),
            'La cookie de la raíz puede ser de otra aplicación del host: no se borra'
        );
    }

    /**
     * Ejecuta la migración legacy privada sin arrancar una sesión real: lo que
     * se verifica es la identidad de sesión que queda activa después.
     *
     * @return array<string, mixed>
     */
    private function runLegacyMigration(): array
    {
        $manager = (new \ReflectionClass(SessionManager::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SessionManager::class, 'migrateLegacyPhpSession');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        return is_array($result) ? $result : [];
    }

    /**
     * Cierra la sesión activa dejando los datos escritos en el almacenamiento,
     * como haría el fin de una petición real.
     */
    private function closeActiveSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
