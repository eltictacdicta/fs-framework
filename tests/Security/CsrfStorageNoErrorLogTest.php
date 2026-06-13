<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * N1 — CsrfManager `error_log()` ruido en producción.
 *
 * @see docs/reviews/audit-2026-06-12-spec.md sección 3.1.4
 */
#[CoversClass(CsrfManager::class)]
final class CsrfStorageNoErrorLogTest extends TestCase
{
    private const SOURCE_FILE = __DIR__ . '/../../src/Security/CsrfManager.php';
    private const FORBIDDEN_MARKER = '[NativeCsrf]';

    /**
     * N1.1: getToken NO escribe en error_log con prefijo "[NativeCsrf]".
     *
     * Capturamos `error_log()` redirigiendo la directiva INI a un
     * archivo temporal. Esta es la forma estándar de capturar
     * `error_log()` en PHP desde CLI (no se puede hookear como un
     * error handler porque es un builtin).
     *
     * NativeSessionCsrfStorage llama a session_start() internamente
     * en su ensureSession(); no necesitamos arrancarla desde el test.
     */
    public function testGetTokenDoesNotWriteToErrorLog(): void
    {
        $logFile = $this->captureErrorLog(function (): void {
            $instance = $this->createNativeStorage();
            $instance->getToken('test_id_no_log');
        });

        $this->assertStringNotContainsString(
            self::FORBIDDEN_MARKER,
            $logFile,
            'getToken() no debe escribir "[NativeCsrf]" en error_log.'
        );
    }

    /**
     * N1.2: setToken NO escribe en error_log con prefijo "[NativeCsrf]".
     */
    public function testSetTokenDoesNotWriteToErrorLog(): void
    {
        $logFile = $this->captureErrorLog(function (): void {
            $instance = $this->createNativeStorage();
            $instance->setToken('test_id_set_no_log', 'tok123');
        });

        $this->assertStringNotContainsString(
            self::FORBIDDEN_MARKER,
            $logFile,
            'setToken() no debe escribir "[NativeCsrf]" en error_log.'
        );
    }

    /**
     * Test estático complementario: verifica que la fuente de
     * CsrfManager.php NO contiene el marcador "[NativeCsrf]".
     *
     * Esto es la red de seguridad definitiva. La captura dinámica
     * cubre la ejecución; el análisis estático cubre la regresión
     * silenciosa si alguien re-introduce el marker en otro punto.
     */
    public function testSourceFileHasNoNativeCsrfErrorLogMarkers(): void
    {
        $this->assertFileExists(self::SOURCE_FILE);

        $content = file_get_contents(self::SOURCE_FILE);
        $this->assertNotFalse($content, 'No se pudo leer CsrfManager.php');

        $this->assertStringNotContainsString(
            self::FORBIDDEN_MARKER,
            $content,
            'CsrfManager.php no debe contener el marcador "[NativeCsrf]" '
            . '(debug logging debe haber sido removido en production code).'
        );
    }

    /**
     * N1.3: el comportamiento funcional permanece intacto.
     *
     * Si las dos líneas de error_log se borran, las funciones siguen
     * retornando/guardando exactamente lo mismo. Este test garantiza
     * que el refactor (borrar 2 líneas) no rompe la API pública.
     */
    public function testFunctionalBehaviorUnaffected(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @session_start();

        try {
            $token = CsrfManager::generateToken('fs_form');

            $this->assertNotSame('', $token, 'generateToken() no debe retornar string vacío.');

            $this->assertTrue(
                CsrfManager::isValid($token, 'fs_form'),
                'Un token recién generado debe validar correctamente.'
            );
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    /**
     * Helper: redirige la directiva INI `error_log` a un archivo
     * temporal, ejecuta el callable, captura el contenido, y restaura.
     *
     * @param callable $action código a ejecutar bajo la captura
     * @return string contenido del log capturado
     */
    private function captureErrorLog(callable $action): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csrf_error_log_');
        $this->assertNotFalse($tmpFile, 'No se pudo crear archivo temporal para capturar error_log.');

        $previous = ini_set('error_log', $tmpFile);
        // Limpia cualquier contenido previo
        file_put_contents($tmpFile, '');

        try {
            $action();
        } finally {
            ini_set('error_log', (string) $previous);
            $contents = file_get_contents($tmpFile);
            @unlink($tmpFile);
        }

        return (string) $contents;
    }

    /**
     * Helper: instancia la clase NativeSessionCsrfStorage que vive en
     * el mismo namespace que CsrfManager. Lo hacemos con Reflection
     * porque la clase no tiene un binding en el Container público.
     */
    private function createNativeStorage(): object
    {
        $nsClass = (new ReflectionClass(CsrfManager::class))->getNamespaceName()
            . '\\NativeSessionCsrfStorage';

        return new $nsClass();
    }
}
