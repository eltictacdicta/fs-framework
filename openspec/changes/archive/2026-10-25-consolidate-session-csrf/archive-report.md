# Archive Report: consolidate-session-csrf

**Archived**: 2026-10-25
**Destination**: `openspec/changes/archive/2026-10-25-consolidate-session-csrf/`
**Mode**: openspec (core `openspec/` — the change touches `src/`, `base/` and `controller/`,
so it is owned by the core tree, never a plugin SDD)
**Artifact store**: `openspec`
**Status**: **ARCHIVED** — delta specs merged into the canonical source of truth
**Verdict at close**: **ARCHIVE-READY** (`verify-report.md`, re-verification section:
0 open CRITICAL, first-run blocker `C-1` **RESOLVED**)
**Task Completion Gate**: `tasks.md` **16/16 checked, 0 unchecked** — gate passed

## Pre-archive gates

| Gate | Requirement | Result |
|---|---|---|
| Artifacts present | `proposal.md`, `design.md`, `specs/` (3 deltas), `tasks.md`, `verify-report.md` | **PASS** — all five present |
| No open CRITICAL in `verify-report.md` | Re-verification must supersede the first run and `C-1` must be marked resolved | **PASS** — the re-verification section is explicitly headed *"SUPERSEDES the original verdict below"*; the per-clause table marks `C-1` **RESOLVED**; the new verdict is **ARCHIVE-READY**. The first-run `DO NOT ARCHIVE AS-IS` block is retained verbatim for history, below an explicit supersession banner |
| No unchecked implementation tasks | `tasks.md` | **PASS** — 16/16 checked (note `S-4`: for this change the checkboxes are claims, not evidence — the verifier established compliance from code and tests, not from the checkboxes) |

## What Shipped

One session identity and one CSRF token path across the whole request lifecycle.
`StealthMode::ensurePhpSessionStarted()` now calls
`session_name(SessionManager::resolveSessionName())` **before** `session_start()`, so the stealth
entry path (`?page=login&adminpanel=HASH`) opens the installation's own `FSSESS_xxx` session
instead of racing PHP's default `PHPSESSID`. `SessionManager::resolveSessionName()` became
`public static`, `SessionManager::initialize()` gained a name-match gate that detects an
already-active correctly-named session and wraps it with `PhpBridgeSessionStorage` without
re-issuing `session_name()`/`session_start()`, and the legacy `PHPSESSID` path was hardened into
**discard** semantics: `discardLegacyPhpSession()` clears the cookie and unsets `$_COOKIE['PHPSESSID']`
without ever opening, reading or copying the foreign session (a deliberate security inversion —
copying a foreign session's keys allowed login injection because `isValid()` checks only
`user_nick` + `login_time` + `last_activity`). On the CSRF side, `CsrfManager::getManager()` runs a
once-per-request token-presence guard that obtains the default token via `getToken()` and caches
the outcome in `static bool $tokenVerified`; the storage (`NativeSessionCsrfStorage`) reads and
writes `$_SESSION['_csrf']` directly, so the token survives `session->migrate(true)` by
construction rather than by re-synchronisation. Net effect: the "Token de seguridad inválido"
error on stealth admin access is closed at the source, by removing the competing session cookie
rather than by patching the token check.

## Spec Merge Summary

| Delta file | Requirement | Canonical target | Action |
|---|---|---|---|
| `specs/session-consolidation/spec.md` | `SC-01`…`SC-05` (5 requirements, 6 scenarios/edge cases) | `openspec/specs/session-consolidation/spec.md` | **CREATED** — new capability, no prior canonical spec existed |
| `specs/session-integrity/spec.md` | `SI-01` (1 requirement, 3 scenarios) | `openspec/specs/session-integrity/spec.md` | **MODIFIED** |
| `specs/login-csrf/spec.md` | `LC-02` (1 requirement, 3 scenarios) | `openspec/specs/login-csrf/spec.md` | **MODIFIED** |

### Promotion mechanics — `session-consolidation` (created)

The delta was already written as a full spec (`# session-consolidation Specification` /
`## Purpose` / `## Requirements` table), so no header rewrite was needed. Mechanical copy per the
sdd-archive Mechanical Copy Contract: `cp` of the delta to a `mktemp` file under
`openspec/specs/session-consolidation/`, followed by a mandatory `diff -r` readback that returned
**empty (exit 0)**, then an atomic `mv` into place. Source and promoted file sha256 both
`f942bf8488d980f522d07a34dbc2509a9fc926c458887948a0b48bd010a0355b`. No requirement content passed
through the model's Read/Write path. Counts at close: **5 requirements, 6 scenarios/edge cases.**

### Merge mechanics — `session-integrity` (`SI-01`) and `login-csrf` (`LC-02`)

Both canonical files are Spanish and both deltas are English. The merge therefore **translated**
the amended requirement text and its scenarios into the language already used by each target file
rather than pasting the English delta, and did not create a bilingual file. This follows the merge
note carried by the `login-csrf` delta itself (*"the canonical file is intentionally left
untranslated in this change"*) and the verify report's `S-1` decision (*"RESOLVED as a decision"*).

| Target | Change applied |
|---|---|
| `session-integrity` | Table row `SI-01` rewritten from *"refrescarse después de `session->migrate(true)` en `save_session_data()`"* to the both-backends validity contract. The superseded scenario *"CSRF token refreshed after session regeneration"* (which asserted the removed re-synchronisation model) was **replaced in place** by a `### Requirement: SI-01` section carrying the real mechanism (`NativeSessionCsrfStorage` / `$_SESSION['_csrf']` / `session_regenerate_id(true)` preserves `$_SESSION` / `getToken()` not `refreshToken()` / per-request cache) plus the delta's three scenarios. `SI-02`…`SI-04` and the `H2` section were left untouched |
| `login-csrf` | Table row `LC-02` rewritten to the unified-session-name contract with backend-consistency and legacy-cookie clauses. A new `### Requirement: LC-02 — Validación CSRF durante el login` section with the delta's three scenarios was inserted after the existing scenario list and before the `H3` section. The pre-existing negative scenario *"CSRF validated during login"* (invalid/absent token rejected) is **kept** — it is not contradicted by the delta and is complementary to the delta's positive scenarios |

### Deliberately preserved clauses

- **`SC-04` discard semantics + the "MUST NOT be reintroduced" rationale.** The canonical text
  states that a request carrying a legacy `PHPSESSID` **MUST NOT** read, open or copy that foreign
  session's data, and the `Security rationale (do not revert)` block is carried over verbatim in
  meaning, ending in the explicit **MUST NOT be reintroduced** prohibition on the removed
  data-copying migration.
- **`SC-01`'s `### Known deviation` section**, naming `controller/admin_agentes.php` (line 271) and
  `src/Security/CsrfManager.php` (line 133) as the two residual bare `session_start()` sites that
  are **separately-tracked follow-ups**, not behaviour this change introduced, plus the installer
  `FSINSTALL_` carve-out.
- **`LC-02`'s legacy-cookie clause** — the canonical requirement states that legacy `PHPSESSID`
  cookies **MUST NOT** cause a token mismatch *after the legacy cookie is discarded (SC-04)*. This
  is the clause the first-run verify flagged as most at risk of silent loss, and it is now in the
  canonical spec.
- **`SI-01`'s real mechanism** — `NativeSessionCsrfStorage` / `$_SESSION['_csrf']` / `getToken()`
  and the per-request cache. The invented "session bag" mechanism is gone from the canonical spec
  entirely (first-run finding `W-1`).

### Language decision (recorded)

The archive brief stated that the canonical specs under `openspec/specs/` are written in Spanish.
Measured at archive time, that is **not** accurate: of the 16 canonical specs, 9 are English
(`content-i18n`, `htmx-core-support`, `htmx-crud`, `schema-sync`, `test-harness-determinism`,
`page-authorization`, `tarifa-familia-hierarchy`, `backup-progress-sse`,
`tarifario-catalog-htmx-pilot` — ~18.3k words) and 7 are Spanish (the `catalog-*` trio,
`list-search-security`, `login-csrf`, `session-integrity`, `user-auth-methods` — ~3.9k words).
English is dominant by file count (9/16) and overwhelmingly by word volume (~82%), and it is the
convention of every canonical spec created since 2026-08. `session-consolidation` was therefore
created in **English**, matching the dominant language of its sibling specs in `openspec/specs/`
and the `htmx-core-support` full-spec convention used as the format reference. The two **modified**
files stayed in the language they already used (Spanish), per the explicit rule for merges.

## Known Documentation Inconsistency — issue `N-1` (recorded, deliberately NOT fixed)

`proposal.md`, `design.md` and `tasks.md` were **not** corrected and **still describe the removed
`migrateLegacyPhpSession()` data-copying migration**, which no longer exists in the code:

- `proposal.md` — scope item "Legacy `PHPSESSID` detection and migration to `FSSESS_xxx`", approach
  step 4 ("copy `$_SESSION` to `FSSESS_xxx`"), the risk row "Legacy session data lost on
  transition", and success criteria "`PHPSESSID` absent after transition".
- `design.md` — the "Migration Path (Legacy PHPSESSID → FSSESS_xxx)" section in full (open legacy
  session, snapshot `$_SESSION`, merge saved data, set `_migrated_from_phpsessid`), the
  "PhpBridgeSessionStorage Sync Contract" table's "session bag" wording, and the testing-strategy
  rows for `migrateLegacyPhpSession()` / `refreshToken()`.
- `tasks.md` — tasks 1.2, 1.4, 1.6, 3.2, the file-change summary row "Add migrateLegacyPhpSession",
  and the verification matrix rows for `SC-04`/`SC-05`.

**This is a known documentation inconsistency of the historical record and is deliberately NOT
edited.** Three reasons: (1) the verify phase's recommendation is explicitly to *record* it, not
resolve it (`verify-report.md`: *"record in `archive-report.md` that `proposal.md`/`design.md`/
`tasks.md` still describe the removed `migrateLegacyPhpSession()` data-copying migration (N-1)"*);
(2) the archive phase must not rewrite the change's own historical documents — they are the audit
trail of what was planned, and the corrected deltas plus this report are the record of what
actually shipped; (3) editing them would break the archived byte-identity of the change folder.
The canonical specs are the source of truth, and they now describe the discard semantics correctly.
A reader of the archived folder must read `specs/` and this report, not `design.md`, for the
migration behaviour.

## Carried-Over Test Baseline

Carried over from `verify-report.md`; **not re-measured at archive** (the archive phase changed no
implementation or test byte, so the baseline cannot have moved).

| Command | Result | Status |
|---|---|---|
| `ddev exec php vendor/bin/phpunit` | **4311 tests, 16196 assertions, 12 failures, 27 skipped** (exit 1) | Carried over |
| `ddev exec php vendor/bin/phpunit --testsuite Security` | **269 tests, 601 assertions, 1 skipped, 0 failures** (exit 0) | Carried over |
| change-scoped subset (6 files) | `OK (54 tests, 132 assertions)` | Carried over |

All **12 full-suite failures are in `plugins/OidcProvider`** — a separate git repository that this
change never touched. They are DB-fixture / schema-drift failures (`Perfil no encontrado`,
collation mismatch, seed-probe failures), not session or CSRF logic; two of them carry "Csrf" in
their names but fail at `assertNotFalse($profile)` after a `seedPendingProfile()` that never
persisted. One of them reproduces in isolation (`--filter`, `Tests: 1, Failures: 1`), which rules
out suite-ordering interaction with this change's tests. They are pre-existing and unrelated.
The `Security` suite — the suite that actually covers this change — is green with zero failures.

## Open Follow-Ups Carried Out of This Change

None of these blocks archiving. All are recorded so the next session does not have to rediscover them.

| ID | Item | Where |
|---|---|---|
| **W-3 residual** | The two bare `session_start()` sites remain: `controller/admin_agentes.php:271` (duplicated-petition guard, rarely reachable) and `src/Security/CsrfManager.php:133` (branch used only when `SessionManager` is unavailable). Documented in the canonical spec's `### Known deviation` section as a separate hardening follow-up; the code fix is not part of this change | `controller/admin_agentes.php:271`, `src/Security/CsrfManager.php:133` |
| **W-4** | `StealthMode`'s cookie sync emits `FSSESS_xxx` with a hardcoded `path => '/'` and no `secure`, while `SessionManager` uses `resolveCookiePath()` + `SecureRequestDetector::isSecure()`. On subdirectory and/or HTTPS installs a single response can carry two same-named cookies with divergent `Path`/`Secure` attributes — a residual variant of the dual-cookie hazard `SC-05` closes. No test can assert `Set-Cookie` from CLI | `src/Core/StealthMode.php:494-499` |
| **W-5** | The E2E deliverable never existed. `tasks.md` 3.3 and `proposal.md` 67 claim a Playwright scenario in `scripts-onlogin/`; that directory does not exist and `git log --all -- 'scripts-onlogin*'` is empty. The user-visible guarantee therefore rests on unit/integration evidence only | `tasks.md:51`, `proposal.md:67` |
| **W-6** | `tests/Integration/CsrfTokenSurvivesMigrationTest` is **non-discriminating**: `generateToken()` auto-creates a token when absent, so the assertion holds with or without the guard. `SI-01`'s core claim ("the token survived") is not protected by a test that can go red | `tests/Integration/CsrfTokenSurvivesMigrationTest.php` |
| **W-7** | `CsrfManager::$tokenVerified` is never invalidated within a request — set once, reset only by tests via Reflection. Latent: it does not bite today because `NativeSessionCsrfStorage` reads raw `$_SESSION` and `fs_login` regenerates only on form login | `src/Security/CsrfManager.php:55,111-114` |

Also carried, non-blocking: `R-2` (SC-05's expiry clause is scoped to requests that reach
`SessionManager::initialize()` before `headers_sent()`), `R-3` (`FSSESS_xxx` is really
`resolveSessionName()`; `FS_SESSION_NAME` can rename it), `S-2` (stale test counts in
`proposal.md:66` / `tasks.md:52`).

## Archive Method & Mechanical Readback

- **Change folder move**: recursive snapshot to `/tmp/opencode/csc-snapshot/` → `git mv
  openspec/changes/consolidate-session-csrf openspec/changes/archive/2026-10-25-consolidate-session-csrf`
  → `diff -r` snapshot vs destination: **empty (exit 0)**. All 7 files preserved with original
  bytes (`proposal.md`, `design.md`, `tasks.md`, `verify-report.md`, and the 3 delta specs — the
  deltas keep the modifications the spec pass made to them).
- **Naming convention**: `YYYY-MM-DD-{change-name}`, matching every existing entry in
  `openspec/changes/archive/`.
- `archive-report.md` is additive and did not exist in the source snapshot.
- **No entry for this change remains in `openspec/changes/` outside `archive/`** — confirmed:
  `openspec/changes/consolidate-session-csrf/` no longer exists, and no active change directory
  carries this name. (`openspec/changes/harden-auth-boundaries/proposal.md` merely *references* it
  as prior art; that change is unrelated and in progress and was not touched.)

### Residual doc staleness (not fixed, not part of the change)

`openspec/project.md` is an auto-generated (`sdd-init`, 2026-07-26) layout snapshot whose
`## OpenSpec Layout` tree still lists `consolidate-session-csrf/` as an active change — and omits
several changes that exist and lists at least one that is already archived. It is stale as a whole,
so patching only this line would be misleading in a different way. Recorded here instead.

## Constraints Honoured

- No implementation code and no test was modified.
- `openspec/changes/harden-auth-boundaries/` was not touched.
- Nothing under `plugins/` was touched.
- The archived `proposal.md`, `design.md` and `tasks.md` were not edited (`N-1` is recorded, not
  fixed).
- Files changed by this archive phase: 1 created canonical spec, 2 modified canonical specs, 1
  moved change folder, 1 new `archive-report.md`.

## SDD Cycle Complete

The change is archived. `session-consolidation` now exists as a canonical capability (5
requirements, 6 scenarios/edge cases); `session-integrity` `SI-01` and `login-csrf` `LC-02` now
describe the shipped mechanism instead of the removed migration and the invented session bag. The
implementation was already live and green; this archive closes the documentation gap that the
first verify run opened and that the re-verification resolved.
