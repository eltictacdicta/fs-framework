<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Api\Exception;

/**
 * Excepción para errores de validación (400)
 *
 * @author FacturaScripts Team
 */
class ValidationException extends ApiException
{
    /** @var string[] */
    protected array $validationErrors = [];

    /**
     * @param string|string[] $errors
     */
    public function __construct(
        string|array $errors = "Error de validación",
        ?array $errorData = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if (is_array($errors)) {
            $this->validationErrors = $errors;
            $message = "Error de validación";
            $errorData = array_merge($errorData ?? [], ['errors' => $errors]);
        } else {
            $message = $errors;
        }
        
        parent::__construct($message, 400, $errorData, $code, $previous);
    }

    /**
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}
