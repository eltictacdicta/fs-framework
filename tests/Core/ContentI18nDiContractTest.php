<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Core;

use FSFramework\DependencyInjection\Container;
use FSFramework\Translation\ContentTranslator;
use FSFramework\Translation\LanguageRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Reference;
use Tests\Support\FakeIdioma;
use Tests\Support\IdiomaModelFakeDb;

require_once FS_FOLDER . '/model/idioma.php';
require_once __DIR__ . '/../Support/IdiomaModelFakeDb.php';
require_once __DIR__ . '/../Support/FakeIdioma.php';

/**
 * CI-17 — namespaced core services cannot be shadowed (design D9).
 *
 * The core registers `core.language_registry` and `core.content_translator`
 * before plugin services load and re-asserts exactly those two definitions
 * afterwards. The assertions stay DB-free: the registered classes and the
 * translator's `Reference` are asserted on the container `Definition`, and the
 * translator is resolved for real against a DB-free registry instance swapped in
 * at the registry id — never against a model that would touch the database.
 */
final class ContentI18nDiContractTest extends TestCase
{
    private const RESERVED_IDS = ['core.language_registry', 'core.content_translator'];

    protected function setUp(): void
    {
        parent::setUp();
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function test_core_services_are_registered_to_the_core_classes(): void
    {
        $container = Container::getContainer();

        $registry = $container->getDefinition('core.language_registry');
        $this->assertSame(LanguageRegistry::class, $registry->getClass());
        $this->assertTrue($registry->isPublic());

        $translator = $container->getDefinition('core.content_translator');
        $this->assertSame(ContentTranslator::class, $translator->getClass());
        $this->assertTrue($translator->isPublic());
    }

    public function test_the_translator_receives_a_reference_to_the_core_registry(): void
    {
        $translator = Container::getContainer()->getDefinition('core.content_translator');
        $arguments = $translator->getArguments();

        $this->assertCount(1, $arguments);
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertSame('core.language_registry', (string) $arguments[0]);
    }

    public function test_the_translator_resolves_to_a_core_instance_wired_to_the_registry(): void
    {
        $container = Container::getContainer();

        // Keep the suite DB-free: swap the registry id for a real registry over
        // the in-memory model double, then resolve the real translator service.
        $model = (new FakeIdioma())->useFakeDb(new IdiomaModelFakeDb([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => true],
        ]));
        $container->set('core.language_registry', new LanguageRegistry($model));

        $this->assertInstanceOf(LanguageRegistry::class, Container::get('core.language_registry'));

        $translator = Container::get('core.content_translator');

        $this->assertInstanceOf(ContentTranslator::class, $translator);
        $this->assertSame('en', $translator->defaultCode(), 'The translator must consume the referenced registry');
    }

    public function test_a_plugin_colliding_id_cannot_replace_the_core_services(): void
    {
        $container = Container::getContainer();

        // Simulate a plugin services.php clobbering the two reserved ids.
        $container->register('core.language_registry', \stdClass::class)->setPublic(true);
        $container->register('core.content_translator', \stdClass::class)->setPublic(true);

        self::restoreReservedServices();

        $this->assertSame(LanguageRegistry::class, $container->getDefinition('core.language_registry')->getClass());
        $this->assertSame(ContentTranslator::class, $container->getDefinition('core.content_translator')->getClass());
        $this->assertSame(
            'core.language_registry',
            (string) $container->getDefinition('core.content_translator')->getArguments()[0]
        );
    }

    public function test_a_plugin_own_bare_id_still_resolves_and_is_not_reserved(): void
    {
        $container = Container::getContainer();
        $container->register('my_plugin.translator', \stdClass::class)->setPublic(true);

        self::restoreReservedServices();

        $this->assertSame(\stdClass::class, $container->getDefinition('my_plugin.translator')->getClass());
        $this->assertInstanceOf(\stdClass::class, Container::get('my_plugin.translator'));
        $this->assertSame(self::RESERVED_IDS, self::reservedIds());
    }

    public function test_exactly_the_two_core_ids_are_reserved(): void
    {
        Container::getContainer();

        $this->assertSame(self::RESERVED_IDS, self::reservedIds());
    }

    public function test_registration_order_is_core_then_plugins_then_reassert(): void
    {
        $reflection = new \ReflectionMethod(Container::class, 'registerCoreServices');
        $lines = file(FS_FOLDER . '/src/DependencyInjection/Container.php');
        $this->assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
        $compact = (string) preg_replace('/\s+/', '', $body);

        $legacy = strpos($compact, 'registerLegacyModels();');
        $contentI18n = strpos($compact, 'registerContentI18nServices();');
        $plugins = strpos($compact, 'loadPluginServices();');
        $restore = strpos($compact, 'restoreReservedServices();');

        $this->assertNotFalse($legacy);
        $this->assertNotFalse($contentI18n);
        $this->assertNotFalse($plugins);
        $this->assertNotFalse($restore);
        $this->assertTrue($legacy < $contentI18n, 'content-i18n services register after legacy models');
        $this->assertTrue($contentI18n < $plugins, 'content-i18n services register before plugin services');
        $this->assertTrue($plugins < $restore, 'reserved ids are re-asserted after plugin services load');
    }

    private static function restoreReservedServices(): void
    {
        $method = new \ReflectionMethod(Container::class, 'restoreReservedServices');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    /**
     * @return list<string>
     */
    private static function reservedIds(): array
    {
        $property = new \ReflectionProperty(Container::class, 'reservedDefinitions');
        $property->setAccessible(true);

        return array_keys((array) $property->getValue());
    }
}
