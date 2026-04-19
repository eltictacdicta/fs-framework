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
/**
 * Tests para validación declarativa de ApiField.
 */

namespace Tests\Api;

use FSFramework\Api\Attribute\ApiField;
use FSFramework\Api\Exception\ValidationException;
use FSFramework\Plugins\api_base\Api\Resource\ResourceTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

class ResourceTransformerTest extends TestCase
{
    public function testFixtureExposesHiddenValueForMetadataCoverage(): void
    {
        $fixture = new ApiFieldFixture();

        $this->assertSame('', $fixture->hiddenValue);
    }

    public function testFilterWritableFieldsAcceptsApiAlias(): void
    {
        $transformer = new ResourceTransformer();

        $filtered = $transformer->filterWritableFields(ApiFieldFixture::class, [
            'display_name' => 'Cliente demo',
            'hiddenValue' => 'secret',
        ]);

        $this->assertSame(['name' => 'Cliente demo'], $filtered);
    }

    public function testValidateWritableFieldsExecutesSymfonyConstraints(): void
    {
        $transformer = new ResourceTransformer();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('display_name:');

        $transformer->validateWritableFields(ApiFieldFixture::class, [
            'name' => 'ab',
        ]);
    }
}

class ApiFieldFixture
{
    #[ApiField(name: 'display_name', validation: [new Assert\NotBlank(), new Assert\Length(min: 3)], required: true)]
    public string $name = '';

    #[ApiField(writable: false)]
    public string $hiddenValue = '';
}
