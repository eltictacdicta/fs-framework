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

use FSFramework\Core\Schema\ContentI18nMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Change-wide isolation gates for content-i18n (CI-15, CI-23, CI-24).
 *
 * The core content-i18n surface must never couple to a plugin-owned
 * translation table or identifier, the migration allowlist must be exactly
 * `['idiomas']`, and the consumer slice must not add a core page, a route or an
 * `#[AdminOnly]` declaration. These are structural source gates, not behavior
 * tests.
 */
final class ContentI18nCoreIsolationTest extends TestCase
{
    /** @var list<string> CI-24 core surface files, relative to FS_FOLDER. */
    private const CI24_CORE_FILES = [
        'src/Translation/LanguageRegistry.php',
        'src/Translation/ContentTranslator.php',
        'model/idioma.php',
        'model/table/idiomas.xml',
        'src/Core/Schema/ContentI18nMigration.php',
    ];

    /** @var list<string> New consumer-surface files authored by this slice. */
    private const SLICE_FILES = [
        'src/Traits/TranslatableControllerTrait.php',
        'themes/AdminLTE/view/Macro/Translation.html.twig',
    ];

    /** @var list<string> */
    private const PLUGIN_TRANSLATION_TABLES = [
        'catalogo_idiomas',
        'articulo_descripciones',
        'catalogo_opcional_idiomas',
    ];

    /** @var list<string> */
    private const PROTECTED_CONSUMER_IDENTIFIERS = [
        'catalogo_idioma',
        'articulo_descripcion',
        'oidc_client',
        'oidc_clients',
        'LocaleManager',
    ];

    /**
     * @return list<string>
     */
    private function storeFiles(): array
    {
        $files = glob(FS_FOLDER . '/src/Translation/Store/*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Remove PHP block/line comments and Twig comments so the identifier gate
     * asserts executable references only. Documentation that names a consumer
     * (for example the D1 docblock that mirrors `catalogo_idioma`) is not a
     * dependency (CI-23).
     */
    private function stripComments(string $source): string
    {
        $stripped = preg_replace('@/\*.*?\*/@s', '', $source) ?? $source;
        $stripped = preg_replace('@//[^\n]*@', '', $stripped) ?? $stripped;

        return preg_replace('@\{#.*?#\}@s', '', $stripped) ?? $stripped;
    }

    /**
     * @return list<string> absolute paths
     */
    private function coreSurfacePaths(): array
    {
        $paths = [];
        foreach (self::CI24_CORE_FILES as $relative) {
            $paths[] = FS_FOLDER . '/' . $relative;
        }
        foreach ($this->storeFiles() as $absolute) {
            $paths[] = $absolute;
        }

        return $paths;
    }

    #[Test]
    public function catalogoIdiomasGateIsCleanOnTheCoreContentSurface(): void
    {
        foreach ($this->coreSurfacePaths() as $path) {
            self::assertFileExists($path);
            $source = (string) file_get_contents($path);

            self::assertSame(
                false,
                strpos($source, 'catalogo_idiomas'),
                $path . ' must not reference catalogo_idiomas (CI-24).'
            );
        }
    }

    #[Test]
    public function coreSurfaceReferencesNoPluginTranslationTable(): void
    {
        foreach ($this->coreSurfacePaths() as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::PLUGIN_TRANSLATION_TABLES as $pluginTable) {
                self::assertSame(
                    false,
                    strpos($source, $pluginTable),
                    $path . ' must stay core-scoped and never reference ' . $pluginTable . ' (CI-15).'
                );
            }
        }
    }

    #[Test]
    public function migrationAllowlistIsExactlyIdiomas(): void
    {
        self::assertSame(['idiomas'], ContentI18nMigration::TABLE_ALLOWLIST);
    }

    #[Test]
    public function consumerSurfaceAddsNoAdminOnlyPageOrRoute(): void
    {
        foreach (self::SLICE_FILES as $relative) {
            $path = FS_FOLDER . '/' . $relative;
            self::assertFileExists($path, $relative . ' must exist for this slice.');
            $source = (string) file_get_contents($path);

            self::assertSame(false, strpos($source, 'AdminOnly'), $relative . ' must not declare an admin-only page (CI-22).');
            self::assertSame(false, strpos($source, 'FSRoute'), $relative . ' must not register a route (CI-22).');
        }
    }

    #[Test]
    public function sliceSurfaceReferencesNoProtectedConsumerIdentifier(): void
    {
        $paths = $this->coreSurfacePaths();
        foreach (self::SLICE_FILES as $relative) {
            $paths[] = FS_FOLDER . '/' . $relative;
        }

        foreach ($paths as $path) {
            self::assertFileExists($path);
            $source = $this->stripComments((string) file_get_contents($path));
            foreach (self::PROTECTED_CONSUMER_IDENTIFIERS as $identifier) {
                self::assertSame(
                    false,
                    strpos($source, $identifier),
                    $path . ' must not reference the protected consumer identifier ' . $identifier . ' (CI-23).'
                );
            }
        }
    }
}
