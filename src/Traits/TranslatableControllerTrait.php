<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
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
declare(strict_types=1);

namespace FSFramework\Traits;

use FSFramework\Translation\Store\TranslationStoreInterface;

/**
 * Consumer mutation helper for content translations (design D10, CI-21).
 *
 * `persistTranslation()` accepts a state change only when all three guards
 * pass, in order: the request is a POST, the actor is an administrator, and the
 * CSRF token is valid. The guards are overridable `protected` seams defaulting
 * to the `fs_controller` surface, so a consumer controller gets the real
 * behavior while tests can drive each outcome without a live request.
 */
trait TranslatableControllerTrait
{
    /**
     * Persist a translation value after enforcing POST-only, administrator and
     * CSRF guards. Returns true only when the store accepted the write.
     */
    protected function persistTranslation(
        TranslationStoreInterface $store,
        string $field,
        ?string $locale,
        ?string $value
    ): bool {
        if (!$this->translationRequestIsPost()) {
            return false;
        }

        if (!$this->translationActorIsAdmin()) {
            return false;
        }

        if (!$this->translationCsrfIsValid()) {
            return false;
        }

        if ($locale === null || $locale === '') {
            return false;
        }

        return $store->set($field, $locale, $value);
    }

    /**
     * Guard seam: only POST may mutate content translations.
     */
    protected function translationRequestIsPost(): bool
    {
        return $this->getRequest()->isMethod('POST');
    }

    /**
     * Guard seam: only administrators may mutate content translations.
     */
    protected function translationActorIsAdmin(): bool
    {
        return !empty($this->user->admin);
    }

    /**
     * Guard seam: a valid CSRF token is required (the controller surface
     * resolves it in `pre_private_core()`).
     */
    protected function translationCsrfIsValid(): bool
    {
        return $this->isCsrfValid();
    }
}
