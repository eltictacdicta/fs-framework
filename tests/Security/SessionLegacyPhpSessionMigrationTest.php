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
 * La cookie PHPSESSID de OTRA aplicación del mismo host sólo se limpia: nunca
 * se abre esa sesión, nunca se copian sus datos y nunca aporta el id de sesión.
 *
 * Abrirla y copiar sus claves permitía inyectar un login: `isValid()` sólo
 * valida `user_nick` + `login_time` + `last_activity`, así que una sesión ajena
 * con esas claves (otra app del host con el `session.save_path` compartido)
 * alcanzaba para entrar.
 */
#[CoversClass(SessionManager::class)]
class SessionLegacyPhpSessionMigrationTest extends TestCase
{
    private string $sessionNameBefore = '';
    private string $sessionIdBefore = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionNameBefore = session_name();
        $this->sessionIdBefore = session_id();

        SessionManager::reset();
        $this->closeActiveSession();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->closeActiveSession();

        session_name($this->sessionNameBefore);
        session_id($this->sessionIdBefore);

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * La limpieza no toca la identidad de la sesión propia: ni el nombre ni el
     * id de sesión cambian, y la cookie legacy deja de estar disponible.
     *
     * En proceso aparte para poder fijar nombre/id de sesión (con salida ya
     * enviada esas llamadas no tendrían efecto y el test pasaría de casualidad).
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[Test]
    public function legacyCookieIsOnlyDiscarded(): void
    {
        $sessionName = SessionManager::resolveSessionName();
        $ownSessionId = '3vjhh21l048tv1b56pp7ugo25q';

        $_COOKIE = [
            $sessionName => $ownSessionId,
            'PHPSESSID' => 'legacyid000000000000000000',
        ];
        session_name($sessionName);
        session_id($ownSessionId);

        // Sanidad: el seteo tiene que haber surtido efecto en este entorno.
        self::assertSame($sessionName, session_name());
        self::assertSame($ownSessionId, session_id());

        $this->runLegacyDiscard();

        self::assertSame(
            $sessionName,
            session_name(),
            'La limpieza legacy no puede cambiar el nombre de la sesión propia'
        );
        self::assertSame(
            $ownSessionId,
            session_id(),
            'La limpieza legacy no puede reemplazar el id de la sesión propia'
        );
        self::assertArrayNotHasKey(
            'PHPSESSID',
            $_COOKIE,
            'La cookie legacy no debe seguir disponible para el resto de la petición'
        );
    }

    /**
     * Sin cookie propia, la limpieza tampoco aporta ningún id: no se adopta el
     * de la sesión ajena.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[Test]
    public function legacyCookieNeverProvidesTheSessionId(): void
    {
        $_COOKIE = ['PHPSESSID' => 'legacyid000000000000000000'];
        session_name(SessionManager::resolveSessionName());
        session_id('');

        $this->runLegacyDiscard();

        self::assertSame(
            '',
            session_id(),
            'El id de la sesión ajena no puede quedar activo'
        );
    }

    /**
     * El merge de datos de la sesión ajena no puede volver: vivía dentro de
     * `initialize()` y no es observable sin arrancar sesiones reales (en CLI ya
     * hay salida enviada, así que la sesión no arranca). Se guarda el marcador
     * que dejaba ese merge como centinela del código.
     */
    #[Test]
    public function foreignSessionDataIsNeverMerged(): void
    {
        $codeOnly = $this->stripComments(
            (string) file_get_contents(FS_FOLDER . '/src/Security/SessionManager.php')
        );

        self::assertStringNotContainsString(
            '_migrated_from_phpsessid',
            $codeOnly,
            'No puede volver el merge de datos de la sesión PHPSESSID ajena'
        );
        self::assertStringNotContainsString(
            'legacyData',
            $codeOnly,
            'No puede volver la lectura de datos de la sesión PHPSESSID ajena'
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
     * Ejecuta la limpieza legacy privada sin arrancar una sesión real: lo que se
     * verifica es que no toca la identidad de sesión ni deja datos.
     */
    private function runLegacyDiscard(): void
    {
        $manager = (new \ReflectionClass(SessionManager::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SessionManager::class, 'discardLegacyPhpSession');
        $method->setAccessible(true);
        $method->invoke($manager);
    }

    /**
     * Deja sólo código: sin comentarios de línea ni de bloque.
     */
    private function stripComments(string $source): string
    {
        $withoutBlocks = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*(//|\*).*$#m', '', $withoutBlocks);
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
