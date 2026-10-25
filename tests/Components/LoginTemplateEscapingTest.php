<?php

declare(strict_types=1);

/**
 * RMR-05 — the login page escapes its error and message blocks.
 *
 * The nickname is interpolated into the "user does not exist" and ban messages,
 * so both blocks are a markup-injection sink reachable by the same anonymous
 * request that VULN-0008 describes. The `raw` filter turned them into an XSS
 * sink on a page rendered for an unauthenticated visitor.
 *
 * The real template is rendered with a read-only FilesystemLoader rooted at the
 * theme view directory, mirroring how the theme loader resolves it
 * (tests/Core/*MacroContractTest.php), plus the handful of Twig functions the
 * login page needs. A source-level assertion pins the same property without a
 * loader, so the contract cannot be lost by a rendering detail.
 */

namespace Tests\Components;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class LoginTemplateEscapingTest extends TestCase
{
    private const TEMPLATE = 'login/default.html.twig';
    private const TEMPLATE_PATH = '/themes/AdminLTE/view/login/default.html.twig';
    private const PAYLOAD = '<script>alert(1)</script>';

    // =====================================================================
    // RMR-05 scenario 1: a script payload renders escaped
    // =====================================================================

    #[Test]
    public function errorBlockRendersThePayloadEscaped(): void
    {
        $html = $this->renderLogin([self::PAYLOAD], []);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString(self::PAYLOAD, $html, 'Ningún bloque puede emitir el payload sin escapar');
        $this->assertStringNotContainsString('<script>alert(1)', $html, 'No se produce un elemento script ejecutable');
    }

    #[Test]
    public function messageBlockRendersThePayloadEscaped(): void
    {
        $html = $this->renderLogin([], [self::PAYLOAD]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString(self::PAYLOAD, $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    #[Test]
    public function surroundingMarkupIsUnchanged(): void
    {
        $html = $this->renderLogin(['primer error'], ['primer mensaje']);

        $this->assertStringContainsString('<div class="alert alert-danger">', $html);
        $this->assertStringContainsString('<div class="alert alert-success">', $html);
        $this->assertStringContainsString('<ul class="list-unstyled">', $html);
        $this->assertStringContainsString('<li>primer error</li>', $html);
        $this->assertStringContainsString('<li>primer mensaje</li>', $html);
    }

    #[Test]
    public function blocksAreAbsentWhenThereIsNothingToRender(): void
    {
        $html = $this->renderLogin([], []);

        $this->assertStringNotContainsString('alert alert-danger', $html);
        $this->assertStringNotContainsString('alert alert-success', $html);
    }

    // =====================================================================
    // RMR-05 scenario 2: neither loop applies the raw filter
    // =====================================================================

    #[Test]
    public function neitherLoginLoopAppliesTheRawFilter(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::TEMPLATE_PATH);

        preg_match_all(
            '/\{%-?\s*for value in fsc\.get_(errors|messages)\(\)\s*-?%\}(.*?)\{%-?\s*endfor\s*-?%\}/s',
            $source,
            $loops,
            PREG_SET_ORDER
        );

        $this->assertCount(2, $loops, 'El template debe seguir renderizando los dos bloques');

        foreach ($loops as $loop) {
            $this->assertStringNotContainsString(
                '|raw',
                $loop[2],
                'El bucle de get_' . $loop[1] . '() no puede aplicar el filtro raw'
            );
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param list<string> $errors
     * @param list<string> $messages
     */
    private function renderLogin(array $errors, array $messages): string
    {
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . '/themes/AdminLTE/view'));

        $twig->addFunction(new TwigFunction('csp_nonce_attr', static fn(): string => ''));
        $twig->addFunction(new TwigFunction('system_logo_url', static fn(): string => ''));
        $twig->addFunction(new TwigFunction('system_name', static fn(): string => 'FSFramework'));
        $twig->addFunction(new TwigFunction('file_exists', static fn(string $path): bool => false));
        $twig->addFunction(new TwigFunction('trans', static fn(string $key): string => $key));
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn(): string => '<input type="hidden" name="_token" value="stub" />',
            ['is_safe' => ['html']]
        ));

        return $twig->render(self::TEMPLATE, ['fsc' => $this->makeControllerStub($errors, $messages)]);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $messages
     */
    private function makeControllerStub(array $errors, array $messages): object
    {
        return new class($errors, $messages) {
            public object $empresa;

            /**
             * @param list<string> $errors
             * @param list<string> $messages
             */
            public function __construct(private array $errors, private array $messages)
            {
                $this->empresa = (object) ['nombrecorto' => 'Pruebas', 'nombre' => 'Pruebas S.A.'];
            }

            /**
             * @return list<string>
             */
            public function get_errors(): array
            {
                return $this->errors;
            }

            /**
             * @return list<string>
             */
            public function get_messages(): array
            {
                return $this->messages;
            }

            public function loginActionUrl(): string
            {
                return 'index.php?page=login';
            }

            public function shouldShowPasswordResetLink(): bool
            {
                return false;
            }
        };
    }
}
