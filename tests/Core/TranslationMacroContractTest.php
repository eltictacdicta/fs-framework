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

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * CI-22: the content-i18n Twig macro is render-only. It renders one escaped
 * labelled field per locale and nothing else: no `<form>`, no CSRF field and no
 * script. The macro renders in isolation through a `FilesystemLoader` rooted at
 * the theme view directory (design D11, pattern of `HtmxMacroContractTest`).
 */
final class TranslationMacroContractTest extends TestCase
{
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    /**
     * @param list<array{code: string, name: string}> $locales
     * @param array<string, string>                   $values
     * @param array<string, mixed>                    $opts
     */
    private function render(string $field, array $locales, array $values, array $opts = []): string
    {
        $twig = new Environment(new FilesystemLoader(FS_FOLDER . self::THEME_VIEW_DIR));
        $caller = $twig->createTemplate(
            "{% import 'Macro/Translation.html.twig' as i18n %}"
            . '{{ i18n.fields(field, locales, values, opts) }}'
        );

        return $caller->render([
            'field' => $field,
            'locales' => $locales,
            'values' => $values,
            'opts' => $opts,
        ]);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function locales(): array
    {
        return [
            ['code' => 'es', 'name' => 'Español'],
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'fr', 'name' => 'Français'],
        ];
    }

    #[Test]
    public function rendersOneInputPerLocale(): void
    {
        $output = $this->render('subtitle', $this->locales(), ['es' => 'Hola', 'en' => 'Hello']);

        self::assertSame(3, substr_count($output, '<input'), 'One input must be rendered per locale.');
        self::assertSame(3, substr_count($output, 'type="text"'));
        self::assertStringContainsString('name="subtitle[es]"', $output);
        self::assertStringContainsString('name="subtitle[fr]"', $output);
    }

    #[Test]
    public function escapesStoredValues(): void
    {
        $output = $this->render('subtitle', $this->locales(), ['es' => '<script>alert(1)</script>']);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        self::assertStringNotContainsString('<script', $output, 'Stored values must be escaped by the macro.');
    }

    #[Test]
    public function labelsEachFieldWithItsLocaleName(): void
    {
        $output = $this->render('subtitle', $this->locales(), []);

        self::assertStringContainsString('for="subtitle_es"', $output);
        self::assertStringContainsString('Español', $output);
        self::assertStringContainsString('Français', $output);
    }

    #[Test]
    public function emitsNoFormNoCsrfAndNoScript(): void
    {
        $output = $this->render('subtitle', $this->locales(), ['es' => 'Hola']);

        self::assertStringNotContainsString('<form', $output);
        self::assertStringNotContainsString('</form>', $output);
        self::assertStringNotContainsString('<script', $output);
        self::assertStringNotContainsString('csrf', $output);
        self::assertSame(false, stripos($output, 'csrf'));
        self::assertSame(false, stripos($output, 'method='));
        self::assertSame(false, stripos($output, 'type="submit"'));
    }

    #[Test]
    public function rendersTextareasWhenMultilineIsRequested(): void
    {
        $output = $this->render('description', $this->locales(), ['es' => 'Hola'], ['multiline' => true]);

        self::assertSame(3, substr_count($output, '<textarea'));
        self::assertSame(0, substr_count($output, '<input'));
        self::assertStringContainsString('name="description[es]"', $output);
    }

    #[Test]
    public function rendersAnEmptyValueWhenNoStoredValueExists(): void
    {
        $output = $this->render('subtitle', $this->locales(), []);

        self::assertSame(3, substr_count($output, 'value=""'));
    }
}
