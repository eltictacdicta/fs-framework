<?php

declare(strict_types=1);

/**
 * Byte-identical opt-in assertions for htmx-crud assets (HCS-09 extended, HCS-16, CRD-05).
 *
 * Verifies that header.html.twig and footer.html.twig carry no references to
 * htmx-crud.js, Sortable.min.js, fs-dialogs.js (beyond the header's global shim),
 * or the HtmxCrud macro. These scripts load ONLY via the boot() macro line.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HtmxCrudOptinTest extends TestCase
{
    private const THEME_VIEW_DIR = '/themes/AdminLTE/view';

    private string $headerPath;
    private string $footerPath;

    protected function setUp(): void
    {
        $this->headerPath = FS_FOLDER . self::THEME_VIEW_DIR . '/header.html.twig';
        $this->footerPath = FS_FOLDER . self::THEME_VIEW_DIR . '/footer.html.twig';
    }

    // =====================================================================
    // HCS-09 extended: header/footer carry no new asset references
    // =====================================================================

    #[Test]
    public function headerHasNoHtmxCrudJsReference(): void
    {
        $content = $this->readFile($this->headerPath);

        $this->assertStringNotContainsString('htmx-crud.js', $content);
    }

    #[Test]
    public function headerHasNoSortableJsReference(): void
    {
        $content = $this->readFile($this->headerPath);

        $this->assertStringNotContainsString('Sortable.min.js', $content);
    }

    #[Test]
    public function headerHasNoCrudMacroReference(): void
    {
        $content = $this->readFile($this->headerPath);

        $this->assertStringNotContainsString('HtmxCrud', $content);
    }

    #[Test]
    public function headerHasNoCrudConfigReference(): void
    {
        $content = $this->readFile($this->headerPath);

        $this->assertStringNotContainsString('data-fs-crud-config', $content);
    }

    #[Test]
    public function footerHasNoHtmxCrudJsReference(): void
    {
        $content = $this->readFile($this->footerPath);

        $this->assertStringNotContainsString('htmx-crud.js', $content);
    }

    #[Test]
    public function footerHasNoSortableJsReference(): void
    {
        $content = $this->readFile($this->footerPath);

        $this->assertStringNotContainsString('Sortable.min.js', $content);
    }

    #[Test]
    public function footerHasNoCrudMacroReference(): void
    {
        $content = $this->readFile($this->footerPath);

        $this->assertStringNotContainsString('HtmxCrud', $content);
    }

    #[Test]
    public function footerHasNoCrudConfigReference(): void
    {
        $content = $this->readFile($this->footerPath);

        $this->assertStringNotContainsString('data-fs-crud-config', $content);
    }

    // =====================================================================
    // fs-dialogs.js: header carries the global shim (loaded globally for
    // bootbox-compatible API), but NOT the crud-specific scripts.
    // =====================================================================

    #[Test]
    public function headerStillHasFsDialogsGlobalShim(): void
    {
        // fs-dialogs.js is loaded globally in header (pre-existing, not part of crud boot).
        // This assertion ensures the shim remains; crud boot loads it for views that need it.
        $content = $this->readFile($this->headerPath);

        $this->assertStringContainsString('fs-dialogs.js', $content);
    }

    // =====================================================================
    // Macro file existence
    // =====================================================================

    #[Test]
    public function htmxCrudMacroFileExists(): void
    {
        $macroPath = FS_FOLDER . self::THEME_VIEW_DIR . '/Macro/HtmxCrud.html.twig';

        $this->assertFileExists($macroPath);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Failed to read {$path}");

        return $content;
    }
}
