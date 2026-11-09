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

namespace Tests\Core;

use FSFramework\Core\Html;
use PHPUnit\Framework\TestCase;

/**
 * Templates see only the $GLOBALS keys translated RainTPL views read
 * (plugins, config2), never $_SERVER, $_ENV, $_COOKIE or other globals.
 */
final class HtmlTemplateGlobalsTest extends TestCase
{
    public function testExposesOnlyPluginsAndConfig2(): void
    {
        $backup = [$GLOBALS['plugins'] ?? null, $GLOBALS['config2'] ?? null];
        $GLOBALS['plugins'] = ['facturacion_base'];
        $GLOBALS['config2'] = ['nf0' => 2];
        $GLOBALS['fs_test_secret'] = 's3cr3t';

        try {
            self::assertSame(
                ['plugins' => ['facturacion_base'], 'config2' => ['nf0' => 2]],
                Html::templateGlobals()
            );
        } finally {
            [$GLOBALS['plugins'], $GLOBALS['config2']] = $backup;
            unset($GLOBALS['fs_test_secret']);
        }
    }

    public function testRenderNoLongerHandsTheWholeGlobalsArrayToTwig(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/src/Core/Html.php');

        self::assertDoesNotMatchRegularExpression('/=\s*\$GLOBALS\s*;/', $source);
        self::assertStringContainsString("\$params['GLOBALS'] = self::templateGlobals();", $source);
    }
}
