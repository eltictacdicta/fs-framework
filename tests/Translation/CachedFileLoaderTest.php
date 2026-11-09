<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Translation;

use FSFramework\Translation\CachedFileLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\MessageCatalogue;

final class CountingYamlLoader implements LoaderInterface
{
    public int $calls = 0;

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        $this->calls++;

        return (new YamlFileLoader())->load($resource, $locale, $domain);
    }
}

final class CachedFileLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfl_' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/cache/*') ?: [], glob($this->dir . '/*') ?: []) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    public function testParsesOnceAndServesTheSameMessagesFromCache(): void
    {
        $file = $this->dir . '/messages.es.yaml';
        file_put_contents($file, "hello: \"Hola %name%\"\nsave: Guardar\n");
        $counting = new CountingYamlLoader();
        $loader = new CachedFileLoader($counting, $this->dir . '/cache');

        $first = $loader->load($file, 'es');
        $second = $loader->load($file, 'es');

        self::assertSame(1, $counting->calls);
        self::assertSame(['hello' => 'Hola %name%', 'save' => 'Guardar'], $second->all('messages'));
        self::assertSame($first->all(), $second->all());
        self::assertSame('es', $second->getLocale());
    }

    public function testAChangedFileIsParsedAgain(): void
    {
        $file = $this->dir . '/messages.es.yaml';
        file_put_contents($file, "save: Guardar\n");
        $counting = new CountingYamlLoader();
        $loader = new CachedFileLoader($counting, $this->dir . '/cache');
        $loader->load($file, 'es');

        // Same second, same mtime: the size still tells the versions apart.
        $mtime = (int) filemtime($file);
        file_put_contents($file, "save: Grabar ya\n");
        touch($file, $mtime);
        clearstatcache();

        self::assertSame(['save' => 'Grabar ya'], $loader->load($file, 'es')->all('messages'));
        self::assertSame(2, $counting->calls);
    }
}
