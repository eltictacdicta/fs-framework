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

use FSFramework\DependencyInjection\Container;
use FSFramework\Translation\FSTranslator;
use FSFramework\Security\SafeRedirect;

/**
 * Controlador para forzar el cambio de contraseña.
 * Se activa cuando el usuario tiene una contraseña insegura (menor a 8 caracteres).
 */
class force_password_change extends fs_controller
{
    /** @var bool Si la contraseña fue cambiada correctamente */
    public $password_changed = false;

    public string $change_reason = 'insecure_password';

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Cambio de contraseña obligatorio', 'admin', false, false);
    }

    protected function public_core()
    {
        $this->template = 'force_password_change';
    }

    protected function private_core()
    {
        $this->template = 'force_password_change';

        $session = $this->getSession();
        $this->change_reason = $session->get('force_password_change_reason', 'insecure_password');

        if ($this->request->isMethod('POST')) {
            $this->processPasswordChange();
        }
    }

    private function processPasswordChange(): void
    {
        if (!$this->validateCsrf()) {
            $this->new_error_msg(FSTranslator::trans('invalid-csrf-token'));
            return;
        }

        $newPassword = (string) $this->request->request->get('new_password');
        $confirmPassword = (string) $this->request->request->get('confirm_password');

        if (empty($newPassword) || empty($confirmPassword)) {
            $this->new_error_msg(FSTranslator::trans('password-required'));
            return;
        }

        if ($newPassword !== $confirmPassword) {
            $this->new_error_msg(FSTranslator::trans('password-reset-passwords-dont-match'));
            return;
        }

        if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 32) {
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-length'));
            return;
        }

        if ($this->user->set_password($newPassword) === false) {
            $this->new_error_msg(FSTranslator::trans('password-change-error'));
            return;
        }

        $this->user->rotate_logkey();

        if ($this->user->save()) {
            $this->password_changed = true;
            $this->completeInitialSetupIfPending();

            $session = $this->getSession();
            $session->remove('force_password_change');
            $session->remove('force_password_change_reason');

            $this->flashMessage(FSTranslator::trans('password-changed-success'));

            SafeRedirect::redirect('index.php');
            return;
        }

        $this->new_error_msg(FSTranslator::trans('password-change-error'));
    }

    private function completeInitialSetupIfPending(): void
    {
        try {
            $userService = Container::get('fs_user');
            if (is_object($userService)
                && method_exists($userService, 'isInitialSetupPending')
                && method_exists($userService, 'completeInitialSetup')
                && $userService->isInitialSetupPending()) {
                $userService->completeInitialSetup();
            }
        } catch (\Throwable $e) {
            $this->resolveLogger()->error('force_password_change::completeInitialSetupIfPending failed.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function resolveLogger()
    {
        try {
            if (Container::has('logger')) {
                return Container::get('logger');
            }
        } catch (\Throwable) {
        }

        return class_exists('fs_core_log') ? new \fs_core_log(__CLASS__) : new class {
            public function error(string $message, array $context = []): void
            {
                error_log($message . (!empty($context['message']) ? ' ' . $context['message'] : ''));
            }
        };
    }

    private function flashMessage(string $message): void
    {
        $session = $this->getSession();
        $messages = $session->get('flash_messages', []);

        if (!is_array($messages)) {
            $messages = [];
        }

        $messages[] = $message;
        $session->set('flash_messages', $messages);
    }

    private function getSession()
    {
        if (class_exists('\FSFramework\Security\SessionManager')) {
            return \FSFramework\Security\SessionManager::getInstance()->getSymfonySession();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $storage = new \Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage();
            $session = new \Symfony\Component\HttpFoundation\Session\Session($storage);
            if (!$session->isStarted()) {
                $session->start();
            }
        } else {
            $storage = new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage();
            $session = new \Symfony\Component\HttpFoundation\Session\Session($storage);
            if (!$session->isStarted()) {
                $session->start();
            }
        }

        return $session;
    }

    /**
     * Verifica si se debe forzar el cambio de contraseña
     */
    public static function shouldForceChange(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true;
    }
}
