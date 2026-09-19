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

require_once FS_FOLDER . '/model/fs_page.php';

/**
 * Attribute whose constructor always throws.
 *
 * It proves the declaration is resolved from the attribute *name* and that
 * `newInstance()` / `getArguments()` are never called, because either would
 * trigger this constructor.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ExplodingAttributeFixture
{
    public function __construct()
    {
        throw new \RuntimeException('The admin-only attribute must never be instantiated.');
    }
}

/**
 * Legacy-shaped controller fixture that carries the obsolete 4th `$admin`
 * constructor flag. The flag is intentionally NOT a declaration source.
 */
final class LegacyAdminFlagControllerFixture
{
    public bool $admin;

    public function __construct(bool $admin = false)
    {
        $this->admin = $admin;
    }
}

#[\FSFramework\Attribute\AdminOnly]
final class LegacyAnnotatedControllerFixture
{
}

#[\FSFramework\Attribute\AdminOnly]
final class ModernAnnotatedControllerFixture
{
}

/**
 * Annotated with a namespaced attribute class that is not autoloadable,
 * mirroring legacy plugin controllers with unreliable namespaced autoloading.
 */
// @phpstan-ignore attribute.notFound (intentionally non-loadable: resolution is by name string)
#[\Vendor\Missing\AdminOnly]
final class NonLoadableAttributeFixture
{
}

#[ExplodingAttributeFixture]
final class ExplodingAnnotatedControllerFixture
{
}

/**
 * PA-01: `#[AdminOnly]` class declaration resolution.
 */
final class AdminOnlyAttributeTest extends TestCase
{
    #[Test]
    public function legacyAndModernControllersResolveToTrue(): void
    {
        self::assertTrue(
            \fs_page::is_admin_only_class(LegacyAnnotatedControllerFixture::class),
            'A legacy controller annotated with #[AdminOnly] must resolve to true.'
        );

        self::assertTrue(
            \fs_page::is_admin_only_class(ModernAnnotatedControllerFixture::class),
            'A modern controller annotated with #[AdminOnly] must resolve to true.'
        );
    }

    #[Test]
    public function nonLoadableAttributeResolvesByNameString(): void
    {
        self::assertFalse(
            class_exists('Vendor\\Missing\\AdminOnly'),
            'Fixture precondition: the attribute class must not be autoloadable.'
        );

        self::assertTrue(
            \fs_page::is_admin_only_class(
                NonLoadableAttributeFixture::class,
                'Vendor\\Missing\\AdminOnly'
            ),
            'Resolution must match the attribute name string without autoloading it.'
        );
    }

    #[Test]
    public function constructorThrowingAttributeIsNeverInstantiated(): void
    {
        self::assertTrue(
            \fs_page::is_admin_only_class(
                ExplodingAnnotatedControllerFixture::class,
                ExplodingAttributeFixture::class
            ),
            'Reading the declaration must not call the attribute constructor.'
        );
    }

    #[Test]
    public function constructorFlagAloneIsNotADeclaration(): void
    {
        $controller = new LegacyAdminFlagControllerFixture(true);

        self::assertTrue($controller->admin, 'Fixture precondition: the flag is set.');
        self::assertFalse(
            \fs_page::is_admin_only_class($controller::class),
            'The 4th $admin constructor flag must not declare an admin-only page.'
        );
    }

    #[Test]
    public function unknownOrEmptyClassResolvesToFalse(): void
    {
        self::assertFalse(\fs_page::is_admin_only_class(''));
        self::assertFalse(\fs_page::is_admin_only_class('Vendor\\Missing\\NoSuchController'));
    }
}
