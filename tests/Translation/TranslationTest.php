<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Translation;

use FSFramework\Translation\FSTranslator;
use FSFramework\Translation\TranslationHelper;
use PHPUnit\Framework\TestCase;

class TranslationTest extends TestCase
{
    protected function setUp(): void
    {
        FSTranslator::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        FSTranslator::reset();
        parent::tearDown();
    }

    public function testTranslatorLoadsCoreTranslationsAndAvailableLanguages(): void
    {
        FSTranslator::initialize(FS_FOLDER);

        $this->assertSame('Guardar', FSTranslator::trans('save'));
        $this->assertSame('es_ES', FSTranslator::getLocale());

        $languages = FSTranslator::getAvailableLanguages();

        $this->assertArrayHasKey('es', $languages);
        $this->assertArrayHasKey('en', $languages);
        $this->assertNotSame('', $languages['es']);
    }

    public function testTranslatorAndHelperExposeLocaleManagementHelpers(): void
    {
        FSTranslator::initialize(FS_FOLDER);
        FSTranslator::setDefaultLocale('en_US');
        TranslationHelper::setLocale('en_US');

        $this->assertSame('en_US', TranslationHelper::getLocale());
        $this->assertSame('Save', TranslationHelper::trans('save'));
        $this->assertSame('Save', \FSFramework\Translation\trans('save'));
        $this->assertSame('Save', \FSFramework\Translation\__('save'));

        TranslationHelper::registerGlobalFunctions();
        TranslationHelper::initializeFromConfig(FS_FOLDER);

        $this->assertArrayHasKey('en', TranslationHelper::getAvailableLanguages());
    }

    public function testResetRestoresTheDeclaredInitialState(): void
    {
        FSTranslator::initialize(FS_FOLDER);
        FSTranslator::setDefaultLocale('en_US');
        FSTranslator::setLocale('en_US');

        FSTranslator::reset();

        // reset() is the isolation seam tests rely on between cases. It must
        // restore BOTH the active and the default locale to the declared
        // initial value: a leaked setDefaultLocale() would otherwise poison
        // the fallbacks of every later test in the same process.
        $this->assertSame('es_ES', FSTranslator::getLocale());
        $this->assertSame('es_ES', FSTranslator::getDefaultLocale());

        FSTranslator::initialize(FS_FOLDER);
        $this->assertSame('Guardar', FSTranslator::trans('save'), 'reset() must not leave the default locale at en_US');
    }
}
