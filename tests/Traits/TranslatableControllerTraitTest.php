<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Traits;

use FSFramework\Traits\TranslatableControllerTrait;
use FSFramework\Translation\Store\TranslationStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CI-21: the consumer mutation helper is POST-only, admin-guarded and
 * CSRF-guarded. The three guards are overridable protected seams, so the whole
 * matrix is exercised with an anonymous trait user and an in-memory store
 * double — no live request, no controller, no database (design D10).
 */
final class TranslatableControllerTraitTest extends TestCase
{
    /**
     * In-memory store double: records every `set()` call so a rejected request
     * can be proven to persist nothing.
     */
    private function store(): object
    {
        return new class() implements TranslationStoreInterface {
            /** @var array<string, string> */
            public array $writes = [];

            public int $setCalls = 0;

            public function get(string $field, string $locale): ?string
            {
                return $this->writes[$field . '|' . $locale] ?? null;
            }

            public function set(string $field, string $locale, ?string $value): bool
            {
                $this->setCalls++;

                if ($value === null || $value === '') {
                    unset($this->writes[$field . '|' . $locale]);

                    return true;
                }

                $this->writes[$field . '|' . $locale] = $value;

                return true;
            }

            public function candidates(string $locale): array
            {
                return [$locale];
            }
        };
    }

    /**
     * Anonymous trait user overriding the three guard seams and recording the
     * order in which they are consulted.
     */
    private function controller(bool $post, bool $admin, bool $csrf): object
    {
        return new class($post, $admin, $csrf) {
            use TranslatableControllerTrait;

            /** @var list<string> */
            public array $guardsChecked = [];

            public function __construct(
                private bool $post,
                private bool $admin,
                private bool $csrf
            ) {
            }

            protected function translationRequestIsPost(): bool
            {
                $this->guardsChecked[] = 'post';

                return $this->post;
            }

            protected function translationActorIsAdmin(): bool
            {
                $this->guardsChecked[] = 'admin';

                return $this->admin;
            }

            protected function translationCsrfIsValid(): bool
            {
                $this->guardsChecked[] = 'csrf';

                return $this->csrf;
            }

            public function persist(
                TranslationStoreInterface $store,
                string $field,
                ?string $locale,
                ?string $value
            ): bool {
                return $this->persistTranslation($store, $field, $locale, $value);
            }
        };
    }

    #[Test]
    public function nonPostMutationIsRejectedAndNothingIsPersisted(): void
    {
        $store = $this->store();
        $controller = $this->controller(post: false, admin: true, csrf: true);

        self::assertFalse(
            $controller->persist($store, 'subtitle', 'es', 'Hola'),
            'A non-POST request must not be allowed to mutate translation state.'
        );
        self::assertSame(0, $store->setCalls, 'The store must never be called for a rejected request.');
        self::assertSame([], $store->writes);
        self::assertSame(['post'], $controller->guardsChecked, 'The POST guard must be evaluated first.');
    }

    #[Test]
    public function nonAdminMutationIsDeniedAndNothingIsPersisted(): void
    {
        $store = $this->store();
        $controller = $this->controller(post: true, admin: false, csrf: true);

        self::assertFalse($controller->persist($store, 'subtitle', 'es', 'Hola'));
        self::assertSame(0, $store->setCalls);
        self::assertSame([], $store->writes);
        self::assertSame(
            ['post', 'admin'],
            $controller->guardsChecked,
            'The admin guard must run after the POST guard and before the CSRF guard.'
        );
    }

    #[Test]
    public function invalidCsrfMutationIsRejectedAndNothingIsPersisted(): void
    {
        $store = $this->store();
        $controller = $this->controller(post: true, admin: true, csrf: false);

        self::assertFalse($controller->persist($store, 'subtitle', 'es', 'Hola'));
        self::assertSame(0, $store->setCalls);
        self::assertSame([], $store->writes);
        self::assertSame(['post', 'admin', 'csrf'], $controller->guardsChecked);
    }

    #[Test]
    public function authorizedPostPersistsTheValue(): void
    {
        $store = $this->store();
        $controller = $this->controller(post: true, admin: true, csrf: true);

        self::assertTrue($controller->persist($store, 'subtitle', 'es', 'Hola'));
        self::assertSame(1, $store->setCalls);
        self::assertSame('Hola', $store->get('subtitle', 'es'));
        self::assertSame(
            ['post', 'admin', 'csrf'],
            $controller->guardsChecked,
            'All three guards must be checked before the store is written.'
        );
    }

    #[Test]
    public function authorizedPostCanClearAStoredValue(): void
    {
        $store = $this->store();
        $store->writes['subtitle|es'] = 'Hola';
        $controller = $this->controller(post: true, admin: true, csrf: true);

        self::assertTrue($controller->persist($store, 'subtitle', 'es', null));
        self::assertSame(1, $store->setCalls);
        self::assertNull($store->get('subtitle', 'es'), 'Clearing must remove the stored value.');
    }
}
