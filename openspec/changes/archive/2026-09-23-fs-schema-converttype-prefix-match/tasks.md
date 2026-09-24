# Tasks: fs-schema-converttype-prefix-match

> CORE change (routing: `openspec/` root, not a plugin openspec). Scope: `base/fs_schema.php` (`:430`) + a new test file + a core `VERSION` bump. Strict TDD is active: RED test first, then GREEN, then REFACTOR. Runner: `ddev exec php vendor/bin/phpunit`.

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ~190–270 (production 1 line + optional 2-line comment; test file ~180–260 for ~26 pinned inputs + Reflection helpers + order-independence + integration; `VERSION` 1 line) |
| Session review budget | 800 changed lines |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR (no chain) |
| Delivery strategy | ask-on-risk |
| Chain strategy | n/a — single PR |

Guard contract (exact lines):

```text
Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low
```

- Estimated changed lines: ~190–270
- 400-line budget risk: Low
- Chained PRs recommended: No
- Decision needed before apply: No

Rationale: this is a single-repo CORE change in `panel-ab`, well under both the
400-line guard and the 800-line session budget, so a single PR suffices. Delivery
strategy is `ask-on-risk`, which only requires a decision above the budget; none
is triggered here. `Chain strategy: pending` means no chain is selected because
none is needed.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| WU1 | Exact-match fix in `convertType()` + full test file | Single PR | `ddev exec php vendor/bin/phpunit --testsuite Core` | PHPUnit Core suite (fake-`fs_db2` `CREATE TABLE` assertion); no real DB/browser boundary exists for this pure mapping fix | Revert `base/fs_schema.php` (`:430`) and delete `tests/Core/FsSchemaConvertTypeTest.php`; no schema/DB state touched |
| WU2 | Core release bump | Single PR (separate commit) | `cat VERSION` (expect `0.22.2`) | N/A — release metadata only | Revert `VERSION` to `0.22.1` |

| Work unit | Depends on |
|---|---|
| WU1 | none |
| WU2 | WU1 (release only after the fix lands) |

## Design corrections (verified in this phase — binding for apply)

1. **`tests/bootstrap.php` does NOT require `base/fs_schema.php`.** It defines `FS_DB_TYPE = 'MYSQL'` but never loads `fs_schema`. The new test file MUST `require_once FS_FOLDER . '/base/fs_schema.php';` itself, mirroring `tests/Core/FsSchemaTest.php:12`.
2. **Blast radius (corrected counts).** `<tipo>timestamp</tipo>` appears in **39** files; **40** is the union including the single `timestamp without time zone` file (`plugins/OidcProvider/model/table/oidc_cliente_profiles.xml`). `plugins/api_base` has **10** files, not 11. (Re-verified with `grep -rl`.)
3. **Test suite is `tests/Core/` (resolved, not an open question).** `phpunit.xml` maps suite `Core` → `tests/Core`; `tests/Core/FsSchemaTest.php` exists; `tests/Base/` has no `fs_schema` test. New file: `tests/Core/FsSchemaConvertTypeTest.php`.
4. **`TIME` is a substring of `TIMESTAMP`.** The integration assertion MUST NOT be `assertStringNotContainsString('TIME', $sql)`; express the negative check as `preg_match('/`created_at`\s+TIME(?!STAMP)/', $sql) === 0`. Presence of `` `created_at` TIMESTAMP `` alone already discriminates from the buggy `` `created_at` TIME ``.
5. **Order-independence discrimination (do not weaken).** Under the buggy prefix code, reversing `$typeMapping` yields `timestamp→TIMESTAMP`, `datetime→DATETIME`, `character varying(6)→CHAR(6)`, versus `TIME`/`DATE`/`VARCHAR(6)` in normal order — so the test fails pre-fix and passes only when matching is order-independent.

## Spec traceability

| Work unit | Requirements satisfied |
|---|---|
| WU1 | `schema-sync` delta, `SS-07` (exact match, order independence, `timestamp`/`datetime`/`timestamp(6)`/`timestamp without time zone`), `SS-07a` (no regression, length suffix, PostgreSQL passthrough, `CREATE TABLE` integration), `SS-07b` (creation-only; no `ALTER`/migration; `TypeNormalizer` untouched) |
| WU2 | None (release metadata) |

---

## Work Unit 1 — Exact-match fix + tests (single PR)

**Files:** `tests/Core/FsSchemaConvertTypeTest.php` (create) · `base/fs_schema.php` (modify).
**Depends on:** none.
**Satisfies:** SS-07, SS-07a, SS-07b (see traceability).
**Verification step:** tasks 2.2, 5.1, 5.2.

### Phase 1 — RED: failing tests first

- [x] 1.1 Create `tests/Core/FsSchemaConvertTypeTest.php` (`declare(strict_types=1)`, namespace `Tests\Core`, `require_once FS_FOLDER . '/base/fs_schema.php';` — the file loads `fs_schema` itself; the bootstrap does not).
- [x] 1.2 Add Reflection helpers: `ReflectionMethod` for private static `convertType()`, `ReflectionProperty` for private static `$typeMapping`, and a fake-`fs_db2` harness mirrored from `tests/Core/FsSchemaTest.php` (`select`/`exec` into `$executed`/`table_exists`/`escape_string`) injected via Reflection.
- [x] 1.3 Data provider pinning all **16** `$typeMapping` keys exactly (MySQL active): `character varying`→`VARCHAR`, `character`→`CHAR`, `text`→`TEXT`, `integer`→`INT`, `smallint`→`SMALLINT`, `bigint`→`BIGINT`, `boolean`→`TINYINT(1)`, `double precision`→`DOUBLE`, `real`→`FLOAT`, `numeric`→`DECIMAL`, `date`→`DATE`, `time`→`TIME`, `timestamp`→`TIMESTAMP`, `datetime`→`DATETIME`, `bytea`→`BLOB`, `serial`→`INT AUTO_INCREMENT`.
- [x] 1.4 Hazard-pair assertions: `character varying(6)`→`VARCHAR(6)` (NOT `CHAR(6)`), `character(10)`→`CHAR(10)`, `integer`/`smallint`/`bigint`→`INT`/`SMALLINT`/`BIGINT` distinct, `date`→`DATE`, `time`→`TIME`, `double precision`→`DOUBLE` (SS-07a).
- [x] 1.5 Length suffix + temporal: `timestamp(6)`→`TIMESTAMP(6)`, `timestamp without time zone`→`TIMESTAMP`; no length appended when the mapped type already has parentheses (`boolean`→`TINYINT(1)`) (SS-07, SS-07a).
- [x] 1.6 PostgreSQL passthrough: `convertType('timestamp', false)` and `convertType('datetime', false)` each return the input unchanged (SS-07a).
- [x] 1.7 Order-independence test (SS-07): snapshot normal-order results for probes `timestamp`, `datetime`, `character varying(6)`; invert `$typeMapping` via `ReflectionProperty` (`array_reverse($map, true)`); assert identical results. Encode the rationale from correction 5 in a comment. Restore `$typeMapping` in `tearDown()`.
- [x] 1.8 `CREATE TABLE` integration (SS-07a): inject the fake DB, `\fs_schema::createTable('probe', $xml)` with `<tipo>timestamp</tipo>` on `created_at`; assert `end($db->executed)` contains `` `created_at` TIMESTAMP `` and `preg_match('/`created_at`\s+TIME(?!STAMP)/', $sql) === 0`. No DB required — fake-`fs_db2` harness, no FK constraints (correction 4).
- [x] 1.9 Run `ddev exec php vendor/bin/phpunit --testsuite Core`; confirm RED (`timestamp`→`TIME`, `datetime`→`DATE`, inverted `character varying(6)`→`CHAR(6)`).

### Phase 2 — GREEN: make it pass

- [x] 2.1 In `base/fs_schema.php` replace `:430` with `if ($baseType === $pgType) {`; keep the loop, the `:424` regex, the length-suffix rule and the PostgreSQL passthrough identical.
- [x] 2.2 Run `ddev exec php vendor/bin/phpunit --testsuite Core`; green.

### Phase 3 — REFACTOR + commit

- [x] 3.1 Add a one-line comment at the match documenting exact-match / order-independent semantics (`addTypeMapping()` keys are exact); no behaviour change; re-run the Core suite.
- [x] 3.2 Commit WU1: `fix(schema): match fs_schema::convertType() mapping keys exactly` — one commit carrying `base/fs_schema.php` + `tests/Core/FsSchemaConvertTypeTest.php` together.

---

## Work Unit 2 — Core release bump (separate commit)

**Files:** `VERSION` (modify).
**Depends on:** WU1.
**Verification step:** task 5.3.

- [x] 4.1 Bump `VERSION` patch `0.22.1` → `0.22.2` (core version source of truth; `fsframework.ini` is plugin-only). Do not edit the descriptive version in `openspec/config.yaml`.
- [x] 4.2 Commit `chore(release): bump core VERSION to 0.22.2`. Tag/push are handled by the `fsframework-core-release` skill at release time, not in this PR diff.

---

## Phase 5 — Final verification

- [x] 5.1 Run `ddev exec php vendor/bin/phpunit --testsuite Core`; green; report the exact test count.
- [x] 5.2 Run `ddev exec php vendor/bin/phpunit` (root suite); report real totals; confirm no new failures (pre-existing `phpunit.xml` exclusions are expected).
- [x] 5.3 `git diff --name-only` shows only `base/fs_schema.php`, `tests/Core/FsSchemaConvertTypeTest.php`, `VERSION` (+ this change's `openspec/` artifacts).

---

## Phase 6 — Operational follow-up & scope guard

- [x] 6.1 **Operational follow-up (do NOT misread as a failed fix):** after this change, `factura_pdf1_settings.created_at`/`.updated_at` on the dev DB remain `TIME` and are NOT auto-repaired — `src/Database/TypeNormalizer::compareDataTypes()` returns `true` for `time` ≡ `TIMESTAMP`, so sync emits no `ALTER`. This is expected. The human confirmed these columns are not in production. Remedy is manual and out-of-band (drop/recreate the dev table, or a one-off `ALTER`), deliberately not in this PR.
- [x] 6.2 **Scope guard:** do NOT modify `src/Database/TypeNormalizer.php`; do NOT add any `ALTER TABLE`, migration, or retroactive repair; do NOT expand to `timestamp with time zone`. Verify `git diff` shows no change under `src/` and no `ALTER TABLE` anywhere.
