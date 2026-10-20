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

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Fatal errors used to print the exception message, file, line and class to any
 * visitor. That leaked DB credentials and filesystem paths, and was unescaped
 * HTML. Details are now shown only with FS_DEBUG; the visitor gets a log
 * reference instead.
 */
final class FatalErrorRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
    }

    /**
     * FS_DEBUG is not defined in the test bootstrap, so this exercises the
     * public rendering path.
     */
    public function testFatalErrorDoesNotLeakInternalsByDefault(): void
    {
        if (defined('FS_DEBUG') && FS_DEBUG) {
            $this->markTestSkipped('FS_DEBUG is enabled: the renderer is expected to show details.');
        }

        $html = fs_render_fatal_error(
            'legacy',
            'SQLSTATE[HY000] access denied for user db with password hunter2',
            '/var/www/html/base/fs_db2.php',
            418,
            'RuntimeException'
        );

        $this->assertStringContainsString('Error interno', $html);
        $this->assertStringNotContainsString('hunter2', $html);
        $this->assertStringNotContainsString('/var/www', $html);
        $this->assertStringNotContainsString('RuntimeException', $html);
        $this->assertStringContainsString('<code>', $html, 'A log reference must be shown so a report is traceable.');
    }

    public function testFatalErrorEscapesTheContextLabel(): void
    {
        if (defined('FS_DEBUG') && FS_DEBUG) {
            $this->markTestSkipped('FS_DEBUG is enabled: the renderer is expected to show details.');
        }

        $html = fs_render_fatal_error('<script>alert(1)</script>', 'boom');

        $this->assertStringNotContainsString('<script>', $html);
    }
}
