<?php

declare(strict_types=1);

namespace FSFramework\Controller;

/**
 * Final, fluent, side-effect-free configuration object for HtmxCrudController.
 *
 * Consumed by Theme macros via $fsc->crud()-> getter chain.
 * Every setter returns $this for fluent chaining; no I/O occurs during configuration.
 */
final class HtmxCrudConfig
{
    private ?string $rowPartial = null;
    private array $orderBy = [];
    private array $searchFields = [];
    private array $filterCheckboxes = [];
    private array $buttons = [];
    private array $rowActions = [];
    private ?array $sortable = null;
    private bool $oobFlash = false;
    private bool $rowSwap = true;

    public function rowPartial(string $partial): self
    {
        $this->rowPartial = $partial;
        return $this;
    }

    public function addOrderBy(array $orderBy): self
    {
        $this->orderBy[] = $orderBy;
        return $this;
    }

    public function addSearchFields(array $fields): self
    {
        $this->searchFields[] = $fields;
        return $this;
    }

    public function addFilterCheckbox(string $field): self
    {
        $this->filterCheckboxes[] = $field;
        return $this;
    }

    public function addButton(string $name, array $options): self
    {
        $this->buttons[] = array_merge(['name' => $name], $options);
        return $this;
    }

    public function addRowAction(string $name, array $options): self
    {
        $this->rowActions[] = array_merge(['name' => $name], $options);
        return $this;
    }

    public function sortable(?array $options): self
    {
        $this->sortable = $options;
        return $this;
    }

    public function oobFlash(bool $on): self
    {
        $this->oobFlash = $on;
        return $this;
    }

    public function rowSwap(bool $on): self
    {
        $this->rowSwap = $on;
        return $this;
    }

    // =====================================================================
    // Getters — used by Macro/HtmxCrud.html.twig via $fsc->crud()->…
    // =====================================================================

    public function getRowPartial(): ?string
    {
        return $this->rowPartial;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    public function getSearchFields(): array
    {
        return $this->searchFields;
    }

    public function getFilterCheckboxes(): array
    {
        return $this->filterCheckboxes;
    }

    public function getButtons(): array
    {
        return $this->buttons;
    }

    public function getRowActions(): array
    {
        return $this->rowActions;
    }

    public function getSortable(): ?array
    {
        return $this->sortable;
    }

    public function getOobFlash(): bool
    {
        return $this->oobFlash;
    }

    public function getRowSwap(): bool
    {
        return $this->rowSwap;
    }
}
