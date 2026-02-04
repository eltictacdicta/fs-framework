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
 * Excepción base para errores de la API
 *
 * @author FacturaScripts Team
 */
class ApiException extends \Exception
{
    protected int $httpStatusCode;
    protected ?array $errorData;
    
    public function __construct(
        string $message = "API Error",
        int $httpStatusCode = 500,
        ?array $errorData = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
        $this->errorData = $errorData;
    }
    
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
    
    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
    
    /**
     * Convierte la excepción a array para respuesta JSON
     * @return array{success: false, error: string, errorType: string, errorData?: array, debug?: array}
     */
    public function toArray(bool $includeTrace = false): array
    {
        $result = [
            'success' => false,
            'error' => $this->getMessage(),
            'errorType' => (new \ReflectionClass($this))->getShortName()
        ];
        
        if ($this->errorData !== null) {
            $result['errorData'] = $this->errorData;
        }
        
        if ($includeTrace) {
            $result['debug'] = [
                'file' => basename($this->getFile()),
                'line' => $this->getLine(),
                'trace' => $this->getTraceAsString()
            ];
        }
        
        return $result;
    }
}
