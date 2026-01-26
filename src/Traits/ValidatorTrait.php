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

namespace FSFramework\Traits;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Trait para añadir validación Symfony a modelos.
 * 
 * Compatible con el método test() legacy de fs_model.
 * Puede usarse en conjunto o como reemplazo.
 * 
 * Uso:
 *   class MiModelo extends fs_model {
 *       use ValidatorTrait;
 *       
 *       #[Assert\NotBlank]
 *       #[Assert\Length(max: 10)]
 *       public $codigo;
 *       
 *       public function test() {
 *           // Usar validación Symfony
 *           if (!$this->validate()) {
 *               return false;
 *           }
 *           // Validaciones legacy adicionales si es necesario
 *           return parent::test();
 *       }
 *   }
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
trait ValidatorTrait
{
    /**
     * Instancia del validador Symfony.
     */
    private static ?ValidatorInterface $validator = null;

    /**
     * Últimos errores de validación.
     * @var array
     */
    protected array $validationErrors = [];

    /**
     * Obtiene el validador Symfony.
     */
    protected static function getValidator(): ValidatorInterface
    {
        if (self::$validator === null) {
            self::$validator = Validation::createValidatorBuilder()
                ->enableAttributeMapping()
                ->getValidator();
        }
        return self::$validator;
    }

    /**
     * Valida el modelo usando constraints de Symfony.
     * 
     * @param string[]|null $groups Grupos de validación (null = todos)
     * @return bool True si la validación pasa
     */
    public function validate(?array $groups = null): bool
    {
        $violations = self::getValidator()->validate($this, null, $groups);
        
        $this->validationErrors = [];
        
        if (count($violations) > 0) {
            $this->processViolations($violations);
            return false;
        }
        
        return true;
    }

    /**
     * Valida propiedades específicas del modelo.
     * 
     * @param string|array $properties Propiedad o lista de propiedades
     * @return bool True si la validación pasa
     */
    public function validateProperties(string|array $properties): bool
    {
        if (is_string($properties)) {
            $properties = [$properties];
        }

        $this->validationErrors = [];
        
        foreach ($properties as $property) {
            if (!property_exists($this, $property)) {
                continue;
            }
            
            $violations = self::getValidator()->validateProperty($this, $property);
            
            if (count($violations) > 0) {
                $this->processViolations($violations);
            }
        }
        
        return empty($this->validationErrors);
    }

    /**
     * Valida un valor contra constraints específicos.
     * 
     * @param mixed $value Valor a validar
     * @param Assert\Constraint|array $constraints Constraint o lista de constraints
     * @return bool True si la validación pasa
     */
    public function validateValue(mixed $value, Assert\Constraint|array $constraints): bool
    {
        $violations = self::getValidator()->validate($value, $constraints);
        
        $this->validationErrors = [];
        
        if (count($violations) > 0) {
            $this->processViolations($violations);
            return false;
        }
        
        return true;
    }

    /**
     * Procesa las violaciones y genera mensajes de error.
     */
    private function processViolations(ConstraintViolationListInterface $violations): void
    {
        foreach ($violations as $violation) {
            $property = $violation->getPropertyPath();
            $message = $violation->getMessage();
            
            $this->validationErrors[$property][] = $message;
            
            // Integración con sistema de errores legacy de fs_model
            if (method_exists($this, 'new_error_msg')) {
                $errorMsg = $property ? "{$property}: {$message}" : $message;
                $this->new_error_msg($errorMsg);
            }
        }
    }

    /**
     * Obtiene los errores de validación.
     * 
     * @return array Errores indexados por propiedad
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Obtiene los errores de validación como lista plana.
     * 
     * @return array Lista de mensajes de error
     */
    public function getValidationErrorMessages(): array
    {
        $messages = [];
        
        foreach ($this->validationErrors as $property => $errors) {
            foreach ($errors as $error) {
                $messages[] = $property ? "{$property}: {$error}" : $error;
            }
        }
        
        return $messages;
    }

    /**
     * Verifica si hay errores de validación.
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    /**
     * Limpia los errores de validación.
     */
    public function clearValidationErrors(): void
    {
        $this->validationErrors = [];
    }

    /**
     * Método helper para crear constraints comunes.
     * Útil para validación dinámica sin atributos.
     */
    public static function constraints(): ConstraintBuilder
    {
        return new ConstraintBuilder();
    }
}

/**
 * Builder fluido para crear constraints de validación.
 */
class ConstraintBuilder
{
    private array $constraints = [];

    public function notBlank(?string $message = null): self
    {
        $options = $message ? ['message' => $message] : [];
        $this->constraints[] = new Assert\NotBlank($options);
        return $this;
    }

    public function notNull(?string $message = null): self
    {
        $options = $message ? ['message' => $message] : [];
        $this->constraints[] = new Assert\NotNull($options);
        return $this;
    }

    public function length(?int $min = null, ?int $max = null, ?string $message = null): self
    {
        $options = array_filter([
            'min' => $min,
            'max' => $max,
            'maxMessage' => $message,
            'minMessage' => $message,
        ]);
        $this->constraints[] = new Assert\Length($options);
        return $this;
    }

    public function email(?string $message = null): self
    {
        $options = $message ? ['message' => $message] : [];
        $this->constraints[] = new Assert\Email($options);
        return $this;
    }

    public function regex(string $pattern, ?string $message = null): self
    {
        $options = ['pattern' => $pattern];
        if ($message) {
            $options['message'] = $message;
        }
        $this->constraints[] = new Assert\Regex($options);
        return $this;
    }

    public function range(?int $min = null, ?int $max = null): self
    {
        $this->constraints[] = new Assert\Range(array_filter([
            'min' => $min,
            'max' => $max,
        ]));
        return $this;
    }

    public function positive(): self
    {
        $this->constraints[] = new Assert\Positive();
        return $this;
    }

    public function positiveOrZero(): self
    {
        $this->constraints[] = new Assert\PositiveOrZero();
        return $this;
    }

    public function choice(array $choices, ?string $message = null): self
    {
        $options = ['choices' => $choices];
        if ($message) {
            $options['message'] = $message;
        }
        $this->constraints[] = new Assert\Choice($options);
        return $this;
    }

    public function date(): self
    {
        $this->constraints[] = new Assert\Date();
        return $this;
    }

    public function dateTime(): self
    {
        $this->constraints[] = new Assert\DateTime();
        return $this;
    }

    public function url(): self
    {
        $this->constraints[] = new Assert\Url();
        return $this;
    }

    public function iban(): self
    {
        $this->constraints[] = new Assert\Iban();
        return $this;
    }

    /**
     * Añade un constraint personalizado.
     */
    public function add(Assert\Constraint $constraint): self
    {
        $this->constraints[] = $constraint;
        return $this;
    }

    /**
     * Obtiene los constraints construidos.
     */
    public function get(): array
    {
        return $this->constraints;
    }
}
