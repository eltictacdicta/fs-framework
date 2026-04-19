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

namespace Tests\Form;

use FSFramework\Form\FormHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class FormModelFixture
{
    public string $nombre = 'Ada';
    public string $email = 'ada@example.com';
    public bool $activo = false;
}

class FormHelperTest extends TestCase
{
    public function testCreateForModelAndApplyToModel(): void
    {
        $model = new FormModelFixture();
        $form = FormHelper::createForModel($model, [
            'nombre' => FormHelper::TEXT,
            'email' => [FormHelper::EMAIL, ['required' => true]],
            'activo' => FormHelper::CHECKBOX,
        ], ['csrf_protection' => false]);

        $this->assertSame('Ada', $form->get('nombre')->getData());
        $this->assertSame('ada@example.com', $form->get('email')->getData());
        $this->assertFalse($form->get('activo')->getData());

        $request = new Request([], [
            'form' => [
                'nombre' => 'Grace',
                'email' => 'grace@example.com',
                'activo' => '1',
            ],
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $this->assertTrue(FormHelper::handleRequest($form, $request));

        $updated = FormHelper::applyToModel($form, $model);

        $this->assertSame('Grace', $updated->nombre);
        $this->assertSame('grace@example.com', $updated->email);
        $this->assertTrue($updated->activo);
    }

    public function testGetErrorsAndRenderExposeInvalidFields(): void
    {
        $form = FormHelper::create('form', ['csrf_protection' => false])
            ->add('nombre', TextType::class, ['constraints' => [new Assert\NotBlank()]])
            ->add('email', EmailType::class, ['constraints' => [new Assert\Email()]])
            ->getForm();

        $request = new Request([], [
            'form' => [
                'nombre' => '',
                'email' => 'invalid-email',
            ],
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        FormHelper::handleRequest($form, $request);

        $errors = FormHelper::getErrors($form);
        $html = FormHelper::render($form, ['class' => 'custom-form']);

        $this->assertArrayHasKey('email', $errors);
        $this->assertStringContainsString('<form action="" method="post" class="custom-form">', $html);
        $this->assertStringContainsString('name="form[email]"', $html);
        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testTypeConstantsMapToSymfonyTypes(): void
    {
        $this->assertSame(TextType::class, FormHelper::TEXT);
        $this->assertSame(EmailType::class, FormHelper::EMAIL);
        $this->assertNotSame('', FormHelper::PASSWORD);
        $this->assertNotSame('', FormHelper::NUMBER);
        $this->assertNotSame('', FormHelper::INTEGER);
        $this->assertNotSame('', FormHelper::MONEY);
        $this->assertNotSame('', FormHelper::CHOICE);
        $this->assertNotSame('', FormHelper::CHECKBOX);
        $this->assertNotSame('', FormHelper::TEXTAREA);
        $this->assertNotSame('', FormHelper::DATE);
        $this->assertNotSame('', FormHelper::HIDDEN);
        $this->assertNotSame('', FormHelper::SUBMIT);
    }
}
