# Archive Report: htmx-crud-methodology

**Archived**: 2026-09-07
**Change**: htmx-crud-methodology (core + `tarif_familias` pilot)
**Verdict**: PASS_WITH_WARNINGS
**SDD Phase**: archive

## What Was Done

Archived the completed htmx-crud-methodology change, syncing delta specs to baseline and creating the new htmx-crud capability spec.

### Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| `htmx-core-support` | Updated | Appended HCS-13..16 (4 new requirements) after existing HCS-01..09 + ABS-01..03. No existing requirements modified or removed. |
| `htmx-crud` | Created | New capability spec with CRD-01..06 (6 requirements). Copied from delta spec as full baseline. |

### Archive Contents

- `proposal.md` — Intent, scope, capabilities, risks, rollback plan
- `explore.md` — Exploration and feasibility analysis
- `design.md` — Architecture decisions (D1 reorder service, D2 OOB flash, D3 per-browser `<tr>` swap), class/file layout, controller port design, testing strategy
- `specs/htmx-core-support/spec.md` — Delta spec (HCS-13..16)
- `specs/htmx-crud/spec.md` — Full capability spec (CRD-01..06)
- `tasks.md` — 33 tasks (32 complete, 1 conditional unchecked)
- `verify-report.md` — Verification: 10/10 requirements, 24/24 scenarios, 97/97 change-related tests passing

## Decisions Made

1. **Reorder service placement (D1)**: `TarifaFamiliaReorder` as plugin-local pure service in `plugins/catalogo_core/Services/` — pure static, unit-testable without DB; persistence via `apply_chapter_map` model adapter.
2. **OOB flash channel (D2)**: Header-based primary (always on) + OOB secondary (opt-in via `oobFlash(true)`). Pilot ships with OOB off (toasts only).
3. **Per-browser `<tr>` swap (D3)**: Row fragments for toggles, tbody for structural changes, OOB always `<template>`-wrapped. Fallback flag `rowSwap: false` if browser regression found.
4. **CSRF token-source rule (CRD-02)**: Embedded `csrf_field()` for no-htmx fallback only; `data-fs-csrf-strip` + `htmx:afterSettle` strip ensures header is sole htmx token source.
5. **`hx-confirm` interception (CRD-06)**: Uses `detail.issueRequest()` (htmx 4.0.0 API), not `detail.proceed()` (htmx 2). fs-dialogs bootbox shim with `window.confirm` fallback. Bootbox confined to untouched Excel flows.

## Deviations from Design

| Design Element | Actual | Reason |
|---|---|---|
| `htmx:confirm` API | `detail.issueRequest()` (htmx 4.0.0) instead of `detail.proceed()` (htmx 2 parity assumed in design) | htmx 4.0.0 changed the API; code correctly adapted during apply phase |

## Test Results

| Metric | Value |
|--------|-------|
| Change-related tests | 97/97 passing |
| Core HtmxCrud tests | 38 tests, 87 assertions |
| Plugin tests (catalogo_core) | 59 tests, 166 assertions |
| Full PHPUnit suite | 1447 tests, 3566 assertions |
| Errors (unrelated) | 47 (factura_pdf1 missing dependency) |
| Failures (unrelated) | 1 (tarifario gate composition) |
| Requirements covered | 10/10 (HCS-13..16, CRD-01..06) |
| Scenarios covered | 24/24 |

## Follow-ups

1. **Task 3.9** (conditional, unchecked): Base API fix-ups from pilot — 0-line scope if none found. No fix-ups were needed; task can be confirmed retroactively.
2. **Group drag (parent + descendants)**: Explicit non-goal in CRD-04; flat-codes contract is unchanged by it. Future follow-up.
3. **FSAjaxLoader flash adoption**: Documented as optional, not implemented.
4. **resumable.js CDN removal**: Vendoring follow-up (view:743).
5. **Integration tests for `action_reorder`**: Currently tested at service level via `TarifaFamiliaReorderTest` but not at HTTP dispatch level.
6. **Pre-existing test failures**: 47 errors from `factura_pdf1` (missing `business_data/model/empresa.php`), 1 failure from `tarifario` (gate listener). Unrelated to this change.

## Source of Truth Updated

- `openspec/specs/htmx-core-support/spec.md` — now contains HCS-01..16 + ABS-01..03
- `openspec/specs/htmx-crud/spec.md` — new baseline with CRD-01..06

## SDD Cycle Complete

The change has been fully planned, implemented, verified, and archived. Ready for the next change.
