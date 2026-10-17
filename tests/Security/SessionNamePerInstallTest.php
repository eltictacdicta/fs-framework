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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * El nombre de sesión tiene que ser único por INSTALACIÓN.
 *
 * Antes se derivaba de `$_SERVER['DOCUMENT_ROOT']` cuando no había
 * `FS_FOLDER`: la raíz del vhost, que comparten todas las instalaciones del
 * mismo host (una en la raíz, otra en /fsf/, otra en /staging/). Dos
 * instalaciones hermanas terminaban con el mismo nombre de cookie y podían
 * leer o pisar la sesión de la otra — que es como el SSO web perdía su
 * `oidc_sso_state` y el panel su token CSRF.
 *
 * Además, si el slot igual colisiona (por ejemplo un `FS_SESSION_NAME`
 * copiado entre configs), la sesión ajena se detecta con una firma de la
 * instalación y se descarta: falla cerrado y no toca el archivo del vecino.
 */
#[CoversClass(SessionManager::class)]
class SessionNamePerInstallTest extends TestCase
{
    /**
     * Las tres copias de la fórmula (core moderno, fachada legacy y modo
     * mantenimiento) no pueden sembrar con la raíz del vhost.
     */
    #[Test]
    public function sessionNameSeedNeverComesFromTheSharedHostRoot(): void
    {
        foreach (['src/Security/SessionManager.php', 'base/fs_session_manager.php', 'base/fs_maintenance_mode.php'] as $relativePath) {
            $codeOnly = $this->stripComments((string) file_get_contents(FS_FOLDER . '/' . $relativePath));

            self::assertStringNotContainsString(
                'DOCUMENT_ROOT',
                $codeOnly,
                $relativePath . ' no puede derivar el nombre de sesión de DOCUMENT_ROOT (raíz compartida del vhost)'
            );

            self::assertStringNotContainsString(
                'SCRIPT_FILENAME',
                $codeOnly,
                $relativePath . ' no puede derivar el nombre de sesión de SCRIPT_FILENAME (varía por entrypoint)'
            );
        }
    }

    #[Test]
    public function defaultSessionNameComesFromThePerInstallSecret(): void
    {
        if (defined('FS_SESSION_NAME')) {
            self::assertSame(trim((string) FS_SESSION_NAME), SessionManager::resolveSessionName());

            return;
        }

        self::assertStringStartsWith('session:', SessionManager::installSeed());
        self::assertSame(
            SessionManager::defaultSessionNameFor(SessionManager::installSeed()),
            SessionManager::resolveSessionName(),
            'El nombre por defecto sale de la semilla de la instalación (secreto persistido), no de la ruta'
        );
    }

    #[Test]
    public function installSignatureIsDerivedFromTheSameSeed(): void
    {
        self::assertSame(sha1(SessionManager::installSeed()), SessionManager::installSignature());
        self::assertNotSame(
            SessionManager::installSignatureFor('/var/www/html/fsf'),
            SessionManager::installSignature(),
            'La firma de OTRA instalación (otra semilla) no puede coincidir con la propia'
        );
    }

    #[Test]
    public function twoInstallsOnTheSameHostGetDifferentNames(): void
    {
        $hostRoot = '/var/www/html';

        $names = [
            SessionManager::defaultSessionNameFor($hostRoot),
            SessionManager::defaultSessionNameFor($hostRoot . '/fsf'),
            SessionManager::defaultSessionNameFor($hostRoot . '/staging'),
        ];

        self::assertCount(3, array_unique($names), 'Instalaciones hermanas no pueden compartir nombre de sesión');
    }

    #[Test]
    public function installSignatureDiffersPerInstall(): void
    {
        self::assertNotSame(
            SessionManager::installSignatureFor('/var/www/html'),
            SessionManager::installSignatureFor('/var/www/html/fsf'),
            'La firma de pertenencia tiene que distinguir instalaciones distintas'
        );
    }

    /**
     * Sesión de OTRA instalación (misma cookie por colisión): se descarta y se
     * arranca una limpia, sin tocar el archivo ajeno.
     */
    #[Test]
    public function sessionFromAnotherInstallIsDiscardedFailClosed(): void
    {
        // Instalación hermana en el mismo host (otra ruta ⇒ otra firma).
        $foreignInstallRoot = '/var/www/html/fsf';

        $session = $this->sessionWith([
            '_fs_install_signature' => SessionManager::installSignatureFor($foreignInstallRoot),
            'oidc_sso_state' => 'state-de-otra-app',
            'user_nick' => 'otro-admin',
        ]);
        $foreignId = $session->getId();

        $this->runOwnershipCheck($session);

        self::assertNull($session->get('oidc_sso_state'), 'El state de la app ajena no puede quedar en la sesión');
        self::assertNull($session->get('user_nick'), 'El login de la app ajena no puede quedar en la sesión');
        self::assertNotSame($foreignId, $session->getId(), 'Debe arrancar con un id nuevo, no reutilizar el ajeno');
        self::assertSame(
            SessionManager::installSignature(),
            $session->get('_fs_install_signature'),
            'La sesión limpia queda firmada por esta instalación'
        );
    }

    /**
     * Sesión propia: no se toca.
     */
    #[Test]
    public function ownSessionKeepsItsData(): void
    {
        $session = $this->sessionWith([
            '_fs_install_signature' => SessionManager::installSignature(),
            'oidc_sso_state' => 'state-propio',
        ]);
        $ownId = $session->getId();

        $this->runOwnershipCheck($session);

        self::assertSame('state-propio', $session->get('oidc_sso_state'));
        self::assertSame($ownId, $session->getId(), 'La sesión propia no debe regenerarse');
    }

    /**
     * Sesión anterior a esta versión (sin firma): se adopta y se firma, para
     * que actualizar no cierre las sesiones en curso.
     */
    #[Test]
    public function unsignedSessionIsAdoptedAndStamped(): void
    {
        $session = $this->sessionWith(['user_nick' => 'admin']);
        $id = $session->getId();

        $this->runOwnershipCheck($session);

        self::assertSame('admin', $session->get('user_nick'), 'Una sesión sin firma es válida: no cierra el login');
        self::assertSame($id, $session->getId(), 'No se regenera una sesión legítima');
        self::assertSame(SessionManager::installSignature(), $session->get('_fs_install_signature'));
    }

    /**
     * Sesión firmada con la FIRMA ANTERIOR (seed = raíz, previo al secreto): se
     * adopta y se re-sella, para que el cambio de seed no desloguee a nadie.
     */
    #[Test]
    public function legacySignatureSessionIsAdoptedAndRestamped(): void
    {
        $session = $this->sessionWith([
            '_fs_install_signature' => SessionManager::legacyInstallSignature(),
            'user_nick' => 'admin',
        ]);
        $id = $session->getId();

        $this->runOwnershipCheck($session);

        self::assertSame('admin', $session->get('user_nick'), 'La sesión previa se conserva');
        self::assertSame($id, $session->getId(), 'No se regenera: se reutiliza la sesión existente');
        self::assertSame(
            SessionManager::installSignature(),
            $session->get('_fs_install_signature'),
            'La firma antigua se re-sella con la nueva'
        );
    }

    /**
     * El id se regenera ANTES de limpiar: `session_regenerate_id(false)` guarda
     * el $_SESSION actual en el ID viejo, así que al revés se vaciaría la sesión
     * de la otra instalación (le borraríamos login, CSRF y state del SSO).
     */
    #[Test]
    public function foreignSessionRegeneratesBeforeClearing(): void
    {
        $session = $this->recordingSession();
        $session->set('_fs_install_signature', SessionManager::installSignatureFor('/var/www/html/fsf'));
        $session->set('oidc_sso_state', 'state-de-otra-app');
        $session->calls = [];

        $this->runOwnershipCheck($session);

        self::assertSame(
            ['migrate', 'clear'],
            $session->calls,
            'Primero se rota el id y recién después se limpia, para no vaciar la sesión ajena'
        );
        self::assertNull($session->get('oidc_sso_state'));
    }

    /**
     * Si el id no puede rotarse (headers ya enviados o sesión no activa) no hay
     * separación posible: se descarta lo cargado SIN limpiar, para no escribir
     * datos vacíos sobre la sesión de la otra instalación.
     */
    #[Test]
    public function foreignSessionWithoutRotationIsNotCleared(): void
    {
        $session = $this->recordingSession($rotationSucceeds = false);
        $session->set('_fs_install_signature', SessionManager::installSignatureFor('/var/www/html/fsf'));
        $session->set('oidc_sso_state', 'state-de-otra-app');
        $session->calls = [];

        $this->runOwnershipCheck($session);

        self::assertSame(
            ['migrate'],
            $session->calls,
            'Sin rotación no se limpia nada: la sesión ajena queda intacta'
        );
        self::assertNotSame(
            SessionManager::installSignature(),
            $session->get('_fs_install_signature'),
            'Una sesión que no se pudo rotar no queda adoptada'
        );
    }

    /**
     * Sesión espía que registra el orden de regenerate/clear.
     */
    private function recordingSession(bool $rotationSucceeds = true): Session
    {
        $session = new class(new MockArraySessionStorage(), $rotationSucceeds) extends Session {
            public array $calls = [];

            public function __construct(private MockArraySessionStorage $mockStorage, private bool $rotationSucceeds)
            {
                parent::__construct($mockStorage);
            }

            public function migrate(bool $destroy = false, ?int $lifetime = null): bool
            {
                $this->calls[] = 'migrate';

                return $this->rotationSucceeds ? parent::migrate($destroy, $lifetime) : false;
            }

            public function clear(): void
            {
                $this->calls[] = 'clear';
                parent::clear();
            }
        };

        $session->start();

        return $session;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sessionWith(array $data): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        foreach ($data as $key => $value) {
            $session->set($key, $value);
        }

        return $session;
    }

    private function runOwnershipCheck(Session $session): void
    {
        $manager = (new \ReflectionClass(SessionManager::class))->newInstanceWithoutConstructor();

        $sessionProperty = new \ReflectionProperty(SessionManager::class, 'session');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($manager, $session);

        $method = new \ReflectionMethod(SessionManager::class, 'enforceInstallSessionOwnership');
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
}
