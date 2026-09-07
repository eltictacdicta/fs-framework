# Proposal: HTMX CRUD Methodology (core + `tarif_familias` pilot)

## Intent

Close the server-side half of htmx adoption. Client infrastructure is complete and spec'd — htmx 4 / Alpine CSP / SortableJS vendored, opt-in boot macros (HCS-01..12, ABS-01..03) — yet `fs_controller::isHtmxRequest()` (HCS-06) has **zero production consumers**: no server fragment-render path exists. The `tarif_familias` screen (catalogo_core) is the pilot target: a 767-line view driving 8 inline `$.ajax` JSON calls with `location.reload()` after every mutation, jQuery-UI drag with client-side level recalculation, bootbox confirms, hand-rolled toasts, and a client-trusting reorder payload. Why now: the missing piece is narrow and well-shaped — a fragment-render + flash bridge in core (PSR-4, usable from legacy plugin controllers), an opt-in declarative CRUD base + theme macro, a server-authoritative reorder contract, and the pilot port that proves the methodology reusable by other plugins.

## Scope

### In Scope
- **Core fragment infra**: `FSFramework\Controller\HtmxCrudController extends \fs_controller` (`src/Controller/`) with `renderFragment()`/`renderRowFragment()` via `Html::render()` + `template=false` — zero `index.php` changes; flash payload auto-attached.
- **Flash bridge**: `HX-Trigger` event `fs:flash` carrying sanitized `fs_core_log` channels; header/footer untouched (HCS-03/09); `new_message`/`new_error_msg` stay the single API.
- **Declarative base**: fluent config (`rowPartial`, `addOrderBy`, `addSearchFields`, `addFilterCheckbox`, `addButton`, `addRowAction`). FacturaScripts ListController article is conceptual reference only; legacy `fs_list_controller` is not the code foundation.
- **Theme assets**: `Macro/HtmxCrud.html.twig` (table/toolbar/flash/boot; imports Htmx + Alpine macros) and opt-in `view/js/htmx-crud.js` (flash listener, `X-FS-*` footer updater, reorder serializer, same-madre `onMove` guard, `hx-confirm` → core native `<dialog>` interception).
- **Debug channel**: `X-FS-Duration`/`X-FS-Queries`/`X-FS-Transactions` headers always; OOB DebugBar/SQL tier only under `FS_DEBUG`/`FS_DB_HISTORY`.
- **Pilot port** (`plugins/catalogo_core`, `tarif_familias`): list + CRUD modal; toggles → single-row fragments (`closest tr` / `outerHTML`); promote/demote → fragment responses; **server-authoritative reorder** (flat top-to-bottom codes; server validates permutation and recomputes madre/nivel/capitulo; reorder service extracted from `renumber_siblings_group`, tarif_familias.php:459-501); **sibling-only drag** (SortableJS `onMove` guard; reparenting exclusively via promote/demote/edit modal); `hx-confirm` intercepted by `htmx-crud.js` → native `<dialog>`; bootbox survives only in untouched Excel modals.
- **Specs + tests**: delta `htmx-core-support` (HCS-13..16) + new `htmx-crud` (CRD-*); PHPUnit coverage core + plugin regression.

### Out of Scope / Non-goals
- Excel import/export port — stays on current ES modules + bootbox.
- resumable.js CDN removal (view:743) — vendoring follow-up.
- Group drag (parent + descendants) — follow-up; the flat-codes contract is unchanged by it.
- FSAjaxLoader flash adoption — documented as optional, not implemented.
- `fsframework-htmx-crud` skill authoring; pagination support in the declarative base.

## Capabilities

### New Capabilities
- `htmx-crud`: reusable HTMX-first CRUD methodology — declarative base API, fragment endpoint conventions, server-authoritative reorder contract (permutation validation, server renumbering), opt-in/byte-identical guarantees, SortableJS ordering rules.

### Modified Capabilities
- `htmx-core-support`: adds HCS-13 (flash bridge over `HX-Trigger`), HCS-14 (fragment response contract), HCS-15 (`X-FS-*` headers + OOB debug tier), HCS-16 (`htmx-crud.js` as opt-in module loaded only via the HtmxCrud macro).

## Approach

Per explore §4.1–4.7: controller-owned fragment responses (no `index.php` or global branching — preserves opt-in culture and byte-identical behavior everywhere else); `HtmxCrudController` usable from legacy and PSR-4 controllers (dual parent-chain verified); row partials owned by the plugin (single definition reused by page and fragments); `get_next_capitulo` becomes an hx-get preview swap (HTML-first, no JSON envelope).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Controller/HtmxCrudController.php` | New | Fragment render + flash bridge + declarative base |
| `view/js/htmx-crud.js` | New | Opt-in flash/debug/reorder/confirm module |
| `themes/AdminLTE/view/Macro/HtmxCrud.html.twig` | New | List/toolbar/flash macros + boot line |
| `plugins/catalogo_core/controller/tarif_familias.php` | Modified | JSON endpoints → fragments; reorder service; debug `error_log` noise removed |
| `plugins/catalogo_core/View/tarif_familias.html.twig`, `partials/familias/*`, `Macro/TarifarioComponents.html.twig` | Modified | Page shell + row partials; jQuery/`$.ajax`/bootbox removed from migrated flows (Excel modals untouched) |
| `plugins/catalogo_core/tests/` | New | Pilot regression suite |
| `openspec/specs/htmx-core-support/spec.md`, `openspec/specs/htmx-crud/spec.md` | Modified/New | HCS-13..16 deltas; CRD-* spec |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| CSRF field-shadowing: `validateCsrf()` prefers form field over header (fs_controller.php:391-397); a stale embedded `csrf_field()` shadows the fresh `X-CSRF-TOKEN` header | High | Methodology rule: hx-post forms MUST NOT embed `csrf_field()`; inherited header is the token source; CRD spec + verify scenario |
| ~2.0–2.7k changed lines vs 400-line review budget (~5–6×) — *proposal-level* | High | 4 chained PR slices (see Deliverable Strategy); explicit delivery decision required before apply |
| `<tr>` fragment parsing under htmx 4 | Medium | tbody-level swaps preferred; explicit verify scenario per browser |
| Alpine CSP: `Alpine.data()` registration timing, no `eval` | Medium | Register from nonce'd `htmx-crud.js` via `alpine:init`; smoke scenario |
| Single-screen pilot concentrates regression risk — *proposal-level* | Medium | Slices independently verifiable/revertible; Excel flow untouched throughout |
| Byte-identical regression on non-opted views | Low | No header/footer/global edits; macro-import + `isHtmxRequest()` gating; HCS-09-style verification |
| Double-fire mutations on rapid hx clicks | Low | Adopt `duplicated_petition` guard in mutation endpoints |
| `\|raw` flash XSS if a future renderer bypasses sanitization | Medium | Bridge reads only `get_*()` accessors (`sanitizeForDisplay` by construction); invariant documented in HCS-13 |

## Rollback Plan

All slices are additive and opt-in. PR-3/PR-4 (pilot) revert via `git revert`, restoring the pre-change controller/view from git history — no DB schema changes, no data migration. PR-1/PR-2 (core) are inert without a macro import and a controller extending the base, so reverting them touches no other screen; the byte-identical guarantee is verified at every slice. Excel import/export is never modified — no rollback surface there.

## Dependencies

- Existing vendored assets (htmx 4, Alpine CSP, SortableJS) — already in place (HCS-01, ABS-01).
- No new npm/Composer dependencies.
- `catalogo_core` plugin pilot touchpoints (plugin-local repo; this core change references, does not own, plugin internals).

## Success Criteria

- [ ] Non-opted views render byte-identically (HCS-09 verification extended to the new assets).
- [ ] All 8 `$.ajax` + `location.reload()` cycles in migrated `tarif_familias` flows replaced by fragment responses; zero reloads.
- [ ] Reorder accepts flat codes only; non-permutation payloads rejected with nothing saved; server recomputes madre/nivel/capitulo.
- [ ] Toggles/promote/demote respond with row/tbody fragments; messages delivered via `fs:flash`; single messaging API preserved.
- [ ] `hx-confirm` renders the core native `<dialog>` via `htmx-crud.js`; bootbox absent from migrated flows.
- [ ] `X-FS-*` headers on all fragment responses; OOB debug tier gated by `FS_DEBUG`/`FS_DB_HISTORY`.
- [ ] Full PHPUnit suite green under `ddev exec php vendor/bin/phpunit`, including new core and plugin tests.

## Deliverable Strategy

Work-unit commits per slice; 4 chained PRs: core infra (~650–900) → macros (~250–350) → pilot list/CRUD/toggles (~700–900) → reorder (~350–500). Total ~2.0–2.7k lines.

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High
```

## Open Questions

None at product level — drag semantics (sibling-only) and confirm dialogs (`hx-confirm` → native `<dialog>`) were confirmed at pre-proposal handoff. Technical deferrals to design: reorder-service placement (core vs plugin), OOB secondary flash-channel mechanics, per-browser `<tr>` swap parsing strategy.
