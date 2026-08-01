<?php
/**
 * Tests for ViewHookRegistry — core view hook system.
 */

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use FSFramework\View\ViewHookRegistry;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ViewHookRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static state between tests via Reflection
        $ref = new \ReflectionClass(ViewHookRegistry::class);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // =====================================================================
    // register() + has()
    // =====================================================================

    public function testRegisterMakesHasReturnTrue(): void
    {
        ViewHookRegistry::register('my_hook', '@plugin/template.html.twig');
        $this->assertTrue(ViewHookRegistry::has('my_hook'));
    }

    public function testHasReturnsFalseForUnknownHook(): void
    {
        $this->assertFalse(ViewHookRegistry::has('nonexistent'));
    }

    public function testRegisterMultipleTemplatesPerHook(): void
    {
        ViewHookRegistry::register('my_hook', '@a/a.html.twig');
        ViewHookRegistry::register('my_hook', '@b/b.html.twig');
        $this->assertTrue(ViewHookRegistry::has('my_hook'));
    }

    // =====================================================================
    // Duplicate deduplication
    // =====================================================================

    public function testDuplicateTemplateIsDeduplicated(): void
    {
        ViewHookRegistry::register('my_hook', '@plugin/template.html.twig');
        ViewHookRegistry::register('my_hook', '@plugin/template.html.twig');

        // Render and verify only one template is rendered
        $twig = $this->createTwigWithTemplates(['@plugin/template.html.twig' => 'rendered']);
        $output = ViewHookRegistry::render($twig, 'my_hook');

        $this->assertSame('rendered', $output);
    }

    // =====================================================================
    // render()
    // =====================================================================

    public function testRenderReturnsEmptyForUnknownHook(): void
    {
        $twig = $this->createTwigWithTemplates([]);
        $output = ViewHookRegistry::render($twig, 'nonexistent');
        $this->assertSame('', $output);
    }

    public function testRenderConcatenatesMultipleTemplates(): void
    {
        ViewHookRegistry::register('my_hook', '@a/first.html.twig');
        ViewHookRegistry::register('my_hook', '@b/second.html.twig');

        $twig = $this->createTwigWithTemplates([
            '@a/first.html.twig' => '<div>First</div>',
            '@b/second.html.twig' => '<div>Second</div>',
        ]);

        $output = ViewHookRegistry::render($twig, 'my_hook');
        $this->assertSame('<div>First</div><div>Second</div>', $output);
    }

    public function testRenderPassesContextToTemplates(): void
    {
        ViewHookRegistry::register('my_hook', '@plugin/greeting.html.twig');

        $twig = $this->createTwigWithTemplates([
            '@plugin/greeting.html.twig' => 'Hello {{ name }}',
        ]);

        $output = ViewHookRegistry::render($twig, 'my_hook', ['name' => 'World']);
        $this->assertSame('Hello World', $output);
    }

    // =====================================================================
    // Error handling
    // =====================================================================

    public function testRenderSwallowsExceptionAndContinues(): void
    {
        ViewHookRegistry::register('my_hook', '@good.html.twig');
        ViewHookRegistry::register('my_hook', '@bad.html.twig');
        ViewHookRegistry::register('my_hook', '@also_good.html.twig');

        // @bad.html.twig does not exist in the loader → Twig throws
        $twig = $this->createTwigWithTemplates([
            '@good.html.twig' => 'OK1',
            '@also_good.html.twig' => 'OK2',
        ]);

        // Suppress error_log output during test
        $output = @ViewHookRegistry::render($twig, 'my_hook');
        $this->assertSame('OK1OK2', $output);
    }

    public function testRenderReturnsEmptyWhenAllTemplatesFail(): void
    {
        ViewHookRegistry::register('my_hook', '@missing.html.twig');

        $twig = $this->createTwigWithTemplates([]);

        $output = @ViewHookRegistry::render($twig, 'my_hook');
        $this->assertSame('', $output);
    }

    // =====================================================================
    // Static state isolation
    // =====================================================================

    public function testStaticStateIsIsolatedBetweenTests(): void
    {
        // After setUp resets state, has() should return false
        $this->assertFalse(ViewHookRegistry::has('my_hook'));
    }

    // =====================================================================
    // Twig function integration (render_hook / clientes_render_hook)
    // =====================================================================

    public function testRenderHookTwigFunctionDelegatesToRegistry(): void
    {
        ViewHookRegistry::register('test_hook', '@test/hello.html.twig');

        $twig = $this->createTwigWithTemplates([
            '@test/hello.html.twig' => 'Hello from hook',
            'caller.html.twig' => '{{ render_hook("test_hook") }}',
        ]);

        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            fn(string $name, array $context = []) => ViewHookRegistry::render($twig, $name, $context)
        ));

        $output = $twig->render('caller.html.twig');
        $this->assertSame('Hello from hook', $output);
    }

    public function testRenderHookWithContext(): void
    {
        ViewHookRegistry::register('ctx_hook', '@test/ctx.html.twig');

        $twig = $this->createTwigWithTemplates([
            '@test/ctx.html.twig' => 'Value: {{ val }}',
            'caller_ctx.html.twig' => '{{ render_hook("ctx_hook", {"val": 42}) }}',
        ]);

        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            fn(string $name, array $context = []) => ViewHookRegistry::render($twig, $name, $context)
        ));

        $output = $twig->render('caller_ctx.html.twig');
        $this->assertSame('Value: 42', $output);
    }

    public function testRenderHookReturnsEmptyForUnknownHook(): void
    {
        $twig = $this->createTwigWithTemplates([
            'caller_empty.html.twig' => '{{ render_hook("nonexistent") }}',
        ]);

        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            fn(string $name, array $context = []) => ViewHookRegistry::render($twig, $name, $context)
        ));

        $output = $twig->render('caller_empty.html.twig');
        $this->assertSame('', $output);
    }

    public function testClientesRenderHookTriggersDeprecation(): void
    {
        ViewHookRegistry::register('dep_hook', '@test/dep.html.twig');

        $twig = $this->createTwigWithTemplates([
            '@test/dep.html.twig' => 'Deprecated output',
            'caller_dep.html.twig' => '{{ clientes_render_hook("dep_hook") }}',
        ]);

        $twig->addFunction(new \Twig\TwigFunction(
            'clientes_render_hook',
            function (string $hook, array $context = []) use ($twig) {
                trigger_error('clientes_render_hook() is deprecated. Use render_hook() instead.', E_USER_DEPRECATED);
                return ViewHookRegistry::render($twig, $hook, $context);
            }
        ));

        $deprecationTriggered = false;
        set_error_handler(function (int $errno) use (&$deprecationTriggered) {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
                return true;
            }
            return false;
        }, E_USER_DEPRECATED);

        try {
            $output = $twig->render('caller_dep.html.twig');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered, 'Expected E_USER_DEPRECATED to be triggered');
        $this->assertSame('Deprecated output', $output);
    }

    public function testRenderHookRejectsNonStringHookName(): void
    {
        $twig = $this->createTwigWithTemplates([
            'caller_invalid.html.twig' => '{{ render_hook(123) }}',
        ]);

        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            function (mixed $name, array $context = []) use ($twig): string {
                if (!is_string($name)) {
                    return '';
                }
                return ViewHookRegistry::render($twig, $name, $context);
            }
        ));

        $output = $twig->render('caller_invalid.html.twig');
        $this->assertSame('', $output);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function createTwigWithTemplates(array $templates): Environment
    {
        return new Environment(new ArrayLoader($templates));
    }
}
