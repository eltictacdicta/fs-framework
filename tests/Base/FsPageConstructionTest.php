<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Controller\Concerns\ExtractsMethodBody;

require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/model/fs_page.php';

/**
 * Legacy controller fixture that skips `fs_controller::__construct()` so no
 * database connection is opened, and declares the admin-only marker.
 */
#[\FSFramework\Attribute\AdminOnly]
final class AdminOnlyLegacyControllerFixture extends \fs_controller
{
    public function __construct()
    {
    }
}

/**
 * Plain legacy controller fixture without the attribute.
 */
final class OrdinaryLegacyControllerFixture extends \fs_controller
{
    public function __construct()
    {
    }
}

/**
 * Minimal `fs_page` double for the construction paths.
 */
final class ConstructionPageStub
{
    public $title;
    public $folder;
    public $show_on_menu;
    public $important;
    public $admin_only;

    public int $saveCalls = 0;

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(array $props)
    {
        $this->title = $props['title'];
        $this->folder = $props['folder'];
        $this->show_on_menu = $props['show_on_menu'];
        $this->important = $props['important'];
        $this->admin_only = $props['admin_only'];
    }

    public function save(): bool
    {
        $this->saveCalls++;
        return true;
    }
}

/**
 * PA-03 / PA-07 / PA-10: how both construction paths resolve and persist
 * `admin_only`.
 *
 * `check_fs_page()` is private and needs a live database, so the legacy path is
 * verified behaviourally through its pure `mustUpdatePage()` /
 * `updateExistingPage()` collaborators plus a source pin on the method itself.
 * The modern path is pinned by source as well.
 */
final class FsPageConstructionTest extends TestCase
{
    use ExtractsMethodBody;

    private \fs_controller $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = (new \ReflectionClass(\fs_controller::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $props
     */
    private function pageStub(array $props): ConstructionPageStub
    {
        return new ConstructionPageStub(array_merge([
            'title' => 'Title',
            'folder' => 'admin',
            'show_on_menu' => true,
            'important' => false,
            'admin_only' => false,
        ], $props));
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->controller, $args);
    }

    #[Test]
    public function legacyReadsTheAttributeFromTheConcreteController(): void
    {
        $annotated = new AdminOnlyLegacyControllerFixture();
        $ordinary = new OrdinaryLegacyControllerFixture();

        self::assertTrue(\fs_page::is_admin_only_class(get_class($annotated)));
        self::assertFalse(\fs_page::is_admin_only_class(get_class($ordinary)));
    }

    #[Test]
    public function legacyMustUpdatePageDetectsAFlagChange(): void
    {
        $page = $this->pageStub(['admin_only' => false]);

        self::assertTrue(
            (bool) $this->invokePrivate('mustUpdatePage', [$page, 'Title', 'admin', true, false, true]),
            'A change from false to true must force the page update.'
        );

        self::assertFalse(
            (bool) $this->invokePrivate('mustUpdatePage', [$page, 'Title', 'admin', true, false, false]),
            'An unchanged page must not be updated.'
        );
    }

    #[Test]
    public function legacyUpdateExistingPagePersistsTheFlag(): void
    {
        $page = $this->pageStub(['admin_only' => false, 'title' => 'Old']);

        $this->invokePrivate('updateExistingPage', [$page, 'test_page', 'New', 'admin', true, false, true]);

        self::assertTrue($page->admin_only, 'The resolved flag must be written onto the page.');
        self::assertSame(1, $page->saveCalls, 'The updated page must be saved.');
    }

    #[Test]
    public function legacyUpdateWithoutChangesDoesNotSave(): void
    {
        $page = $this->pageStub(['admin_only' => true]);

        $this->invokePrivate('updateExistingPage', [$page, 'test_page', 'Title', 'admin', true, false, true]);

        self::assertSame(0, $page->saveCalls);
    }

    #[Test]
    public function droppingTheAttributeRevokesTheFlagDeliberately(): void
    {
        $page = $this->pageStub(['admin_only' => true, 'title' => 'Old']);

        $this->invokePrivate('updateExistingPage', [$page, 'test_page', 'Old', 'admin', true, false, false]);

        self::assertFalse(
            $page->admin_only,
            'Removing the attribute must downgrade the persisted flag (accepted, documented revocation).'
        );
        self::assertSame(1, $page->saveCalls);
    }

    #[Test]
    public function legacyCheckFsPageCarriesTheResolvedFlag(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/base/fs_controller.php');
        $check = $this->methodBody($source, 'check_fs_page');

        self::assertStringContainsString('fs_page::is_admin_only_class(get_class($this))', $check);
        self::assertStringContainsString("'admin_only' => \$adminOnly", $check);
        self::assertStringContainsString(
            '$this->updateExistingPage($page, $name, $title, $folder, $shmenu, $important, $adminOnly)',
            $check
        );

        $must = $this->methodBody($source, 'mustUpdatePage');
        self::assertStringContainsString('$page->admin_only != $adminOnly', $must);

        $update = $this->methodBody($source, 'updateExistingPage');
        self::assertStringContainsString('$page->admin_only = $adminOnly', $update);
    }

    #[Test]
    public function modernResolveOrCreatePageEscalatesTheFlag(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/src/Core/Base/Controller.php');
        $resolve = $this->methodBody($source, 'resolveOrCreatePage');

        self::assertStringContainsString('resolve_admin_only', $resolve);
        self::assertStringContainsString('is_admin_only_class(static::class)', $resolve);
        self::assertStringContainsString('$existingPage->admin_only = $adminOnly', $resolve);
        self::assertStringContainsString("'admin_only' => \$adminOnly", $resolve);
    }

    #[Test]
    public function absentAttributeAndValueResolveToFalse(): void
    {
        self::assertFalse(\fs_page::resolve_admin_only(false, null));
        self::assertFalse(\fs_page::is_admin_only_class(OrdinaryLegacyControllerFixture::class));
    }
}
