<?php

declare(strict_types=1);

/**
 * HtmxCrudConfig unit tests (CRD-01).
 *
 * Verifies fluent chaining, default values, getters, and side-effect-free config.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\FSFramework\Controller\HtmxCrudConfig::class)]
class HtmxCrudConfigTest extends TestCase
{
    private \FSFramework\Controller\HtmxCrudConfig $config;

    protected function setUp(): void
    {
        $this->config = new \FSFramework\Controller\HtmxCrudConfig();
    }

    #[Test]
    public function allSettersReturnSameInstanceForChaining(): void
    {
        $result = $this->config
            ->rowPartial('test.html.twig')
            ->addOrderBy(['id' => 'asc'])
            ->addSearchFields(['name'])
            ->addFilterCheckbox('active')
            ->addButton('btn', ['label' => 'Go'])
            ->addRowAction('act', ['icon' => 'fa-star'])
            ->sortable(['tbody' => '#tb'])
            ->oobFlash(true)
            ->rowSwap(false);

        $this->assertSame($this->config, $result);
    }

    #[Test]
    public function defaultsAreCorrect(): void
    {
        $this->assertNull($this->config->getRowPartial());
        $this->assertSame([], $this->config->getOrderBy());
        $this->assertSame([], $this->config->getSearchFields());
        $this->assertSame([], $this->config->getFilterCheckboxes());
        $this->assertSame([], $this->config->getButtons());
        $this->assertSame([], $this->config->getRowActions());
        $this->assertNull($this->config->getSortable());
        $this->assertFalse($this->config->getOobFlash());
        $this->assertTrue($this->config->getRowSwap());
    }

    #[Test]
    public function rowPartialGetterReturnsSetValue(): void
    {
        $this->config->rowPartial('partials/fam_row.html.twig');
        $this->assertSame('partials/fam_row.html.twig', $this->config->getRowPartial());
    }

    #[Test]
    public function additiveMethodsAccumulateEntries(): void
    {
        $this->config
            ->addOrderBy(['capitulo' => 'asc'])
            ->addOrderBy(['nombre' => 'desc'])
            ->addSearchFields(['nombre', 'codigo'])
            ->addSearchFields(['descripcion'])
            ->addFilterCheckbox('b_solo_activas')
            ->addFilterCheckbox('b_con_stock')
            ->addButton('nueva', ['label' => 'Nueva'])
            ->addButton('importar', ['label' => 'Importar'])
            ->addRowAction('editar', ['icon' => 'fa-edit'])
            ->addRowAction('eliminar', ['icon' => 'fa-trash']);

        $this->assertCount(2, $this->config->getOrderBy());
        $this->assertCount(2, $this->config->getSearchFields());
        $this->assertCount(2, $this->config->getFilterCheckboxes());
        $this->assertCount(2, $this->config->getButtons());
        $this->assertCount(2, $this->config->getRowActions());
        $this->assertSame('nueva', $this->config->getButtons()[0]['name']);
        $this->assertSame('editar', $this->config->getRowActions()[0]['name']);
    }

    #[Test]
    public function sortableGetterReturnsSetValue(): void
    {
        $opts = ['tbody' => '#familias-tbody', 'url' => '/reorder', 'handle' => '.drag-handle'];
        $this->config->sortable($opts);
        $this->assertSame($opts, $this->config->getSortable());
    }

    #[Test]
    public function oobFlashAndRowSwapGettersReturnSetValues(): void
    {
        $this->config->oobFlash(true)->rowSwap(false);
        $this->assertTrue($this->config->getOobFlash());
        $this->assertFalse($this->config->getRowSwap());
    }

    #[Test]
    public function configurationIsSideEffectFree(): void
    {
        ob_start();
        $this->config
            ->rowPartial('test.html.twig')
            ->addOrderBy(['id' => 'asc'])
            ->addSearchFields(['name'])
            ->addFilterCheckbox('active')
            ->addButton('btn', ['label' => 'Go'])
            ->addRowAction('act', ['icon' => 'fa-star'])
            ->sortable(['tbody' => '#tb'])
            ->oobFlash(true)
            ->rowSwap(false);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }
}
