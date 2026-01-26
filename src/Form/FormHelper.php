<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Form;

use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use FSFramework\Security\CsrfManager;

/**
 * Helper para crear formularios Symfony en FSFramework.
 * 
 * Proporciona una forma sencilla de crear formularios con:
 * - Protección CSRF automática
 * - Validación integrada
 * - Renderizado compatible con Bootstrap
 * 
 * Uso básico:
 *   $form = FormHelper::create()
 *       ->add('nombre', TextType::class, ['label' => 'Nombre'])
 *       ->add('email', EmailType::class)
 *       ->add('guardar', SubmitType::class)
 *       ->getForm();
 *   
 *   if ($form->isSubmitted() && $form->isValid()) {
 *       $data = $form->getData();
 *   }
 * 
 * Uso con modelo:
 *   $form = FormHelper::createForModel($cliente, [
 *       'nombre' => TextType::class,
 *       'email' => EmailType::class,
 *   ]);
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class FormHelper
{
    private static ?FormFactoryInterface $factory = null;

    /**
     * Obtiene la factory de formularios.
     */
    public static function getFactory(): FormFactoryInterface
    {
        if (self::$factory === null) {
            $csrfManager = CsrfManager::getManager();

            self::$factory = Forms::createFormFactoryBuilder()
                ->addExtension(new CsrfExtension($csrfManager))
                ->getFormFactory();
        }

        return self::$factory;
    }

    /**
     * Crea un FormBuilder vacío.
     * 
     * @param string $name Nombre del formulario
     * @param array $options Opciones del formulario
     * @return FormBuilderInterface
     */
    public static function create(string $name = 'form', array $options = []): FormBuilderInterface
    {
        $defaultOptions = [
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'fs_form',
        ];

        return self::getFactory()->createNamedBuilder(
            $name,
            FormType::class,
            null,
            array_merge($defaultOptions, $options)
        );
    }

    /**
     * Crea un formulario para un modelo/entidad.
     * 
     * @param object $model El modelo con los datos
     * @param array $fields Definición de campos [nombre => tipo o [tipo, opciones]]
     * @param array $options Opciones del formulario
     * @return FormInterface
     */
    public static function createForModel(
        object $model,
        array $fields,
        array $options = []
    ): FormInterface {
        $builder = self::create($options['name'] ?? 'form', $options);

        foreach ($fields as $fieldName => $fieldDef) {
            if (is_string($fieldDef)) {
                // Solo el tipo
                $builder->add($fieldName, $fieldDef);
            } elseif (is_array($fieldDef)) {
                // [tipo, opciones]
                $type = $fieldDef[0] ?? TextType::class;
                $fieldOptions = $fieldDef[1] ?? [];
                $builder->add($fieldName, $type, $fieldOptions);
            }
        }

        $form = $builder->getForm();
        $form->setData(self::modelToArray($model, array_keys($fields)));

        return $form;
    }

    /**
     * Procesa un formulario desde el request.
     * 
     * @param FormInterface $form El formulario
     * @param \Symfony\Component\HttpFoundation\Request|null $request El request
     * @return bool True si el formulario es válido
     */
    public static function handleRequest(
        FormInterface $form,
        ?\Symfony\Component\HttpFoundation\Request $request = null
    ): bool {
        if ($request === null) {
            $request = \FSFramework\Core\Kernel::request();
        }

        $form->handleRequest($request);

        return $form->isSubmitted() && $form->isValid();
    }

    /**
     * Aplica los datos del formulario a un modelo.
     * 
     * @param FormInterface $form El formulario con datos
     * @param object $model El modelo a actualizar
     * @return object El modelo actualizado
     */
    public static function applyToModel(FormInterface $form, object $model): object
    {
        $data = $form->getData();

        foreach ($data as $field => $value) {
            if (property_exists($model, $field)) {
                $model->$field = $value;
            }
        }

        return $model;
    }

    /**
     * Convierte un modelo a array para el formulario.
     */
    private static function modelToArray(object $model, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            if (property_exists($model, $field)) {
                $data[$field] = $model->$field;
            }
        }

        return $data;
    }

    /**
     * Obtiene los errores del formulario como array.
     */
    public static function getErrors(FormInterface $form): array
    {
        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            $field = $error->getOrigin()->getName();
            $errors[$field][] = $error->getMessage();
        }

        return $errors;
    }

    /**
     * Renderiza un formulario como HTML Bootstrap.
     * 
     * @param FormInterface $form El formulario
     * @param array $options Opciones de renderizado
     * @return string HTML del formulario
     */
    public static function render(FormInterface $form, array $options = []): string
    {
        $view = $form->createView();
        $html = '';

        // Abrir form tag
        $action = $options['action'] ?? '';
        $method = $options['method'] ?? 'post';
        $class = $options['class'] ?? 'form';
        
        $html .= "<form action=\"{$action}\" method=\"{$method}\" class=\"{$class}\">\n";

        // Renderizar cada campo
        foreach ($view as $child) {
            $html .= self::renderField($child);
        }

        $html .= "</form>\n";

        return $html;
    }

    /**
     * Renderiza un campo individual.
     */
    private static function renderField($fieldView): string
    {
        $name = $fieldView->vars['full_name'];
        $id = $fieldView->vars['id'];
        $value = $fieldView->vars['value'] ?? '';
        $label = $fieldView->vars['label'] ?? ucfirst($fieldView->vars['name']);
        $type = $fieldView->vars['block_prefixes'][1] ?? 'text';
        $errors = $fieldView->vars['errors'] ?? [];

        $html = "<div class=\"form-group\">\n";

        // Hidden fields sin label
        if ($type === 'hidden') {
            $html .= "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$value}\">\n";
            $html .= "</div>\n";
            return $html;
        }

        // Submit buttons
        if ($type === 'submit' || $type === 'button') {
            $html .= "<button type=\"submit\" class=\"btn btn-primary\" name=\"{$name}\">{$label}</button>\n";
            $html .= "</div>\n";
            return $html;
        }

        // Label
        if ($label !== false) {
            $html .= "<label for=\"{$id}\">{$label}</label>\n";
        }

        // Input
        $inputType = match ($type) {
            'email' => 'email',
            'password' => 'password',
            'number', 'integer', 'money' => 'number',
            'textarea' => 'textarea',
            'checkbox' => 'checkbox',
            default => 'text',
        };

        $errorClass = count($errors) > 0 ? ' is-invalid' : '';

        if ($inputType === 'textarea') {
            $html .= "<textarea name=\"{$name}\" id=\"{$id}\" class=\"form-control{$errorClass}\">{$value}</textarea>\n";
        } elseif ($inputType === 'checkbox') {
            $checked = $value ? ' checked' : '';
            $html .= "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" class=\"form-check-input{$errorClass}\"{$checked}>\n";
        } else {
            $html .= "<input type=\"{$inputType}\" name=\"{$name}\" id=\"{$id}\" value=\"{$value}\" class=\"form-control{$errorClass}\">\n";
        }

        // Errors
        foreach ($errors as $error) {
            $html .= "<div class=\"invalid-feedback\">{$error->getMessage()}</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Tipo de campos disponibles (atajos).
     */
    public const TEXT = TextType::class;
    public const EMAIL = EmailType::class;
    public const PASSWORD = PasswordType::class;
    public const NUMBER = NumberType::class;
    public const INTEGER = IntegerType::class;
    public const MONEY = MoneyType::class;
    public const CHOICE = ChoiceType::class;
    public const CHECKBOX = CheckboxType::class;
    public const TEXTAREA = TextareaType::class;
    public const DATE = DateType::class;
    public const HIDDEN = HiddenType::class;
    public const SUBMIT = SubmitType::class;
}
