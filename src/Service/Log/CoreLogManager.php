<?php

namespace FSFramework\Service\Log;

/**
 * Gestor de logs del sistema
 */
class CoreLogManager
{
    /**
     * Lista de mensajes
     */
    private array $messages = [];

    /**
     * Lista de errores
     */
    private array $errors = [];

    /**
     * Lista de consejos
     */
    private array $advices = [];

    /**
     * Añade un nuevo mensaje
     */
    public function newMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Añade un nuevo error
     */
    public function newError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Añade un nuevo consejo
     */
    public function newAdvice(string $advice): void
    {
        $this->advices[] = $advice;
    }

    /**
     * Devuelve la lista de mensajes
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Devuelve la lista de errores
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Devuelve la lista de consejos
     */
    public function getAdvices(): array
    {
        return $this->advices;
    }

    /**
     * Guarda los logs en la base de datos
     */
    public function save(string $type = 'error', string $model = ''): bool
    {
        // Implementar la lógica para guardar los logs en la base de datos
        return true;
    }
}
