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

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_settings.php';

/**
 * The system logo is served same-origin from FS_MYDOCS. SVG is an XML
 * document that may carry <script> and event handlers, so it does not fire
 * through <img> but does execute when the file is opened directly.
 */
final class SystemLogoTypeTest extends TestCase
{
    public function testPngAndJpegAreAccepted(): void
    {
        $this->assertTrue(\fs_settings::isValidSystemLogoType('png', 'image/png'));
        $this->assertTrue(\fs_settings::isValidSystemLogoType('jpg', 'image/jpeg'));
        $this->assertTrue(\fs_settings::isValidSystemLogoType('JPEG', 'image/jpeg'));
    }

    public function testSvgIsRejected(): void
    {
        $this->assertFalse(\fs_settings::isValidSystemLogoType('svg', 'image/svg+xml'));
    }

    /**
     * Extension and detected MIME must both match the allowlist.
     */
    public function testMismatchedExtensionAndMimeAreRejected(): void
    {
        $this->assertFalse(\fs_settings::isValidSystemLogoType('png', 'image/svg+xml'));
        $this->assertFalse(\fs_settings::isValidSystemLogoType('svg', 'image/png'));
        $this->assertFalse(\fs_settings::isValidSystemLogoType('php', 'text/x-php'));
    }
}
