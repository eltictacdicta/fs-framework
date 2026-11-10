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

namespace Tests\Event;

use FSFramework\Event\ControllerEvent;
use FSFramework\Event\FSEventDispatcher;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * fs_extensions is queried only when one of its events is used, not on
 * every request that touches the dispatcher.
 */
final class LegacyExtensionsLazyLoadTest extends TestCase
{
    public static int $queries = 0;

    /** @var list<object> rows the fake fs_extension::all() returns */
    public static array $rows = [];

    #[RunInSeparateProcess]
    public function testExtensionsAreLoadedOnlyForTheirEventsAndOnce(): void
    {
        self::$rows = [(object) ['type' => 'button', 'name' => 'b1'], (object) ['type' => 'css', 'name' => 'c1']];
        require __DIR__ . '/Fixtures/fs_extension.php';

        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->dispatch(new Event(), 'model.before_save');
        self::assertSame(0, self::$queries, 'Unrelated events must not query fs_extensions.');

        $event = $dispatcher->dispatch(new ControllerEvent('admin_home', 'render'), ControllerEvent::RENDER);
        self::assertSame(1, self::$queries);
        self::assertCount(1, $event->getLegacyExtensions(), 'The button extension reaches controller.render.');

        $dispatcher->dispatch(new ControllerEvent('admin_home', 'render'), ControllerEvent::RENDER);
        self::assertTrue($dispatcher->hasListeners('view.assets'));
        self::assertSame(1, self::$queries, 'Loaded once per dispatcher.');
    }

    #[RunInSeparateProcess]
    public function testListingAllListenersLoadsTheExtensions(): void
    {
        self::$rows = [(object) ['type' => 'js', 'name' => 'j1']];
        require __DIR__ . '/Fixtures/fs_extension.php';

        self::assertArrayHasKey('view.assets', FSEventDispatcher::getInstance()->getListeners());
    }
}
