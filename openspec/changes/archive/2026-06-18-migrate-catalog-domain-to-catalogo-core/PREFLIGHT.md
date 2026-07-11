# SDD Preflight — migrate-catalog-domain-to-catalogo-core

Locked at the start of the session. Do not renegotiate without explicit user request.

## Execution mode
`interactive` — orchestrator pauses between phases and asks before launching the next one.

## Artifact store
`openspec` (B1) — files in `openspec/changes/migrate-catalog-domain-to-catalogo-core/`. Engram copy is maintained as a side-effect (hybrid) for cross-session recovery, but the source of truth is the filesystem.

## Chained PR strategy
`ask-always` (C1) — orchestrator stops and asks before each `sdd-apply` slice if the workload forecast exceeds the review budget.

**Chain strategy (locked 2026-06-16)**: `stacked-to-main` (F1). Each PR merges to `main` in order. PR-A (facturacion_base) → PR1 (panel-ab plumbing) → PR2..N (per-entity verticals) → WU-Final. Rationale: PR1 is plumbing-only (zero behavior change), PR2..N are independent entity verticals, rollback is per-PR (no cascade). User chose F1 over F2 (feature-branch-chain) on 2026-06-16 after the orchestrator recommendation.

## Review budget
**600 lines per PR** (user overrode the default 400 on 2026-06-16). Rationale: Twig views range from 100-450 lines each; with compat layer + per-entity tests a per-entity PR will exceed 400. 600 is the user's explicit ceiling.

## Scope decisions (locked)

| ID | Decision |
|---|---|
| A2 | Scope: catalog core (7 entities) + adjacent tables (13 entities). TPV (`terminal_caja`, `caja`) stays in `facturacion_base`. |
| B1 | Page names: keep legacy (`ventas_articulo`, `ventas_familia`, `ventas_fabricante`, `admin_paises`, etc.). No URL renames. |
| C3 | Retrocompat: 30-line stub files in `facturacion_base/model/*.php` are REMOVED; centralized compat layer added in `catalogo_core/compat/` (using `class_alias` + `@deprecated`). Internal call sites migrate to PSR-4 gradually. |
| D1 | Adjacent models: `git mv` 13 PHP files + 13 XML files. Namespace `FSFramework\model` stays intact. |
| E3 | Delivery: PR1 plumbing (no behavior change) + chained vertical per-entity PRs. |

## Cross-repo coordination

`plugins/facturacion_base/` is a separate git repo. The deletion PR in facturacion_base lands FIRST in its own repo, then the receiving PR1 in panel-ab (`catalogo_core`) imports the deleted files. Without this order, facturacion_base's plugin would briefly reference classes that no longer exist in its own tree.

## TDD

Strict TDD is active per `openspec/config.yaml` (`strict_tdd: true`). All work in `sdd-apply` is red-green-refactor with PHPUnit 11. The `fsframework-test-writing` skill is mandatory for any new test file.
