<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * fs_excel debe cargar Composer desde FS_FOLDER (ruta real en disco) y nunca
 * desde FS_PATH (prefijo web calculado de $_SERVER['SCRIPT_NAME']).
 *
 * Confundirlos rompe en producción en cuanto la aplicación se sirve desde un
 * subdirectorio: se armaría algo como /subdirectorio/vendor/autoload.php, que
 * no existe, y los exports que pasan por fs_excel fallan con
 * "PhpSpreadsheet no está instalado" aunque la librería esté presente.
 *
 * El bug no se puede reproducir en proceso, porque el bootstrap de la suite ya
 * definió FS_PATH como cadena vacía (que es justo el caso donde funciona por
 * casualidad). Por eso se ejecuta en un subproceso con FS_PATH no vacío.
 */
final class FsExcelAutoloadPathTest extends TestCase
{
    /**
     * Ejecuta un subproceso con las constantes indicadas y devuelve su salida.
     *
     * @param array<string, string> $constants
     */
    private function runProbe(array $constants): string
    {
        $defines = '';
        foreach ($constants as $name => $value) {
            $defines .= sprintf("define('%s', '%s');\n", $name, addslashes($value));
        }

        $probe = '<?php '
            . $defines
            . 'require ' . var_export(FS_FOLDER . '/base/fs_excel.php', true) . ";\n"
            . "try {\n"
            . "    fs_excel::load_autoloader();\n"
            . "    echo class_exists(\\PhpOffice\\PhpSpreadsheet\\Spreadsheet::class) ? 'OK' : 'AUTOLOAD_SIN_CLASE';\n"
            . "} catch (\\Throwable \$e) {\n"
            . "    echo 'THREW: ' . \$e->getMessage();\n"
            . "}\n";

        $script = tempnam(sys_get_temp_dir(), 'fsexcel_probe_');
        self::assertNotFalse($script);
        file_put_contents($script, $probe);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($command);

        unlink($script);

        return trim((string) $output);
    }

    protected function setUp(): void
    {
        if (!function_exists('shell_exec')) {
            self::markTestSkipped('shell_exec no está disponible para el subproceso.');
        }
    }

    public function testAutoloaderLoadsWhenFsPathPointsToASubdirectory(): void
    {
        // Este es el caso de producción que fallaba: la app en /erp/.
        $output = $this->runProbe([
            'FS_FOLDER' => FS_FOLDER,
            'FS_PATH' => '/erp/',
        ]);

        self::assertSame(
            'OK',
            $output,
            'Con la app en un subdirectorio, fs_excel debe cargar el autoloader desde FS_FOLDER.'
        );
    }

    public function testAutoloaderLoadsWhenFsPathIsTheRootSlash(): void
    {
        $output = $this->runProbe([
            'FS_FOLDER' => FS_FOLDER,
            'FS_PATH' => '/',
        ]);

        self::assertSame('OK', $output);
    }

    public function testAutoloaderLoadsWhenFsPathIsEmpty(): void
    {
        $output = $this->runProbe([
            'FS_FOLDER' => FS_FOLDER,
            'FS_PATH' => '',
        ]);

        self::assertSame('OK', $output);
    }

    public function testAutoloaderFallsBackToItsOwnLocationWithoutFsFolder(): void
    {
        // fs_excel vive en base/, así que puede deducir la raíz él mismo.
        $output = $this->runProbe(['FS_PATH' => '/erp/']);

        self::assertSame(
            'OK',
            $output,
            'Sin FS_FOLDER definido, fs_excel debe deducir la raíz desde su propia ubicación.'
        );
    }

    public function testAutoloaderReportsThePathItTried(): void
    {
        // Apuntando FS_FOLDER a un directorio sin vendor/, el mensaje debe
        // nombrar la ruta intentada para que el fallo sea diagnosticable.
        $output = $this->runProbe([
            'FS_FOLDER' => sys_get_temp_dir() . '/fsexcel_sin_vendor',
            'FS_PATH' => '/erp/',
        ]);

        self::assertStringStartsWith('THREW: PhpSpreadsheet no está instalado', $output);
        self::assertStringContainsString(
            'vendor/autoload.php',
            $output,
            'El mensaje debe incluir la ruta que intentó abrir.'
        );
    }

    public function testSourceNeverBuildsTheAutoloadPathFromFsPath(): void
    {
        $source = file_get_contents(FS_FOLDER . '/base/fs_excel.php');
        self::assertNotFalse($source);

        // Los comentarios sí pueden nombrar FS_PATH para explicar por qué no se
        // usa; lo que no puede hacerlo es el código.
        self::assertStringNotContainsString(
            'FS_PATH',
            $this->codeWithoutComments((string) $source),
            'fs_excel no debe usar FS_PATH en código: es un prefijo web, no una ruta de disco.'
        );
    }

    /**
     * Devuelve el código fuente sin comentarios, para poder distinguir lo que
     * el archivo hace de lo que documenta.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
