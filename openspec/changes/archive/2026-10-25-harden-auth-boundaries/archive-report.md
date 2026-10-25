# Archive Report: harden-auth-boundaries

**Archived**: 2026-10-25
**Destination**: `openspec/changes/archive/2026-10-25-harden-auth-boundaries/`
**Mode**: openspec (core `openspec/` — the change touches `base/`, `src/`, `controller/`,
`themes/`, `tests/`, `api.php`, `cron.php` and `nginx-sample.conf`, so it is owned by the core
tree, never a plugin SDD)
**Artifact store**: `openspec`
**Status**: **ARCHIVED** — seven deltas merged into the canonical source of truth
**Verdict at close**: **ARCHIVE-READY after four corrections**. The independent verify report
(`verify-report.md`) said **"NOT ARCHIVE-READY AS-IS"** on the strength of two CRITICAL findings
(C-1, C-2) plus two pre-merge amendments (W-1, W-2). All four were corrected in the deltas and
committed as `08471e79` (`docs(openspec): correct four delta statements found by the verify
phase`); the verify report was **not** rewritten — a clearly-marked "Verify follow-up" section was
appended to it, recording each correction with the evidence checked, and the archive proceeded on
that basis.
**Task Completion Gate**: `tasks.md` **78/84 checked, 6 unchecked** — every unchecked box is a
PR-body obligation or the PR 4 live retest gate, **not implementation work** (see "Task gate").

## Pre-archive gates

| Gate | Requirement | Result |
|---|---|---|
| Working tree clean | `git status --porcelain` empty before archiving | **PASS** — empty output |
| Artifacts present | `proposal.md`, `design.md`, `specs/` (7 deltas), `tasks.md`, `verify-report.md` | **PASS** — all present, plus `apply-progress.md` |
| Verify blockers corrected | C-1, C-2, W-1, W-2 genuinely present in the deltas | **PASS** — each re-checked in the committed tree (table below) |
| No unchecked implementation tasks | `tasks.md` | **PASS** — 78 checked, 6 unchecked, all PR-body/live-retest obligations |

### The four corrections, re-checked at archive time

| Finding | Correction required | Present in the delta? |
|---|---|---|
| **C-1** | AAG-01's body must name the anonymously served page set, not "`login`" | **YES** — `specs/anonymous-access-gate/spec.md` AAG-01 binds the exemption to "the publicly served page set defined by AAG-06 (`login` and `password_reset`, enumerated once in `fs_auth_request::PUBLIC_PAGES`)", keeps the page-not-shape rule, and carries an inline `*(Amended during verify.)*` note |
| **C-2** | AAG-02's `?page=login%20` assertion must be split | **YES** — two scenarios now: `?page=LOGIN` → `302` ("A non-canonical page value is gated, not exempted") and `?page=login%20` → gate and dispatcher both resolve `login`, gate returns `null` ("A value the dispatcher canonicalises is exempt") |
| **W-2** | DSH-01 must carry the `# nosecret` opt-out | **YES** — `specs/deployment-surface-hygiene/spec.md` DSH-01 names the marker, names the three SHA-1-of-the-empty-string fixtures, and confines it to a reviewed per-line exception without relaxing the adjacency rule |
| **W-1** | SC-01 must name `NativeSessionCsrfStorage::ensureSession()` and its condition | **YES** — `specs/session-consolidation/spec.md` SC-01 and its Known-deviation replacement name the instance method and the one unnamed shape; `specs/session-transport-hardening/spec.md` STH-05 states the same condition |

### Task gate — the six unchecked boxes

No implementation task is open. The six remaining boxes are:

| Line | Unchecked obligation | Kind |
|---|---|---|
| `202` | PR 2 body states the intended lockout-sensitivity change (3 → 6 real failed submissions) | PR body |
| `256` | PR 3 body carries the 20-file reference list and the all-or-nothing rollback warning | PR body |
| `321` | PR 4 live retest gate (reaches the handler? counters isolated?) | Live retest — the apply phase proved **reachability**, not isolation |
| `383` | PR 5 body marks VULN-0005 **open** pending the operator handoff | PR body |
| `443` | PR 6 body states the HSTS coverage boundary and the `FS_HSTS_MAX_AGE=0` escape | PR body |
| `444` | PR 6 body records the two live probes as **owed** (O.3, O.4), not as passed | PR body |

They are carried into this report unticked (see "Open findings" and "Operator handoff"). Ticking
them at archive time would convert an unverified obligation into a false claim.

## What Shipped

Six workstreams, one per finding, merged sequentially to `master`:

| PR | Workstream | Finding | Merge commit | Merge subject |
|---|---|---|---|---|
| 1 | W1 — anonymous access gate | VULN-0002 | `d1b66e53` | Merge PR 1: bind the anonymous gate exemption to the requested page |
| 2 | W2 — login CSRF verdict (+ W2b modern path) | VULN-0003 | `f26ac8ec` | Merge PR 2: make the login CSRF verdict authoritative |
| 3 | W3 — remember-me revocation authority | VULN-0008 | `d576e014` | Merge PR 3: scope remember-me revocation to the authenticated session |
| 4 | W4 — throttle isolation (+ AAG-06) | VULN-0006 | `39449170` | Merge PR 4: isolate the password-reset throttle and make recovery reachable |
| 5 | W5 — deployment surface hygiene | VULN-0005 | `63c11119` | Merge PR 5: deployment surface hygiene |
| 6 | W6 — session transport hardening | VULN-0007 | `90e9c313` | Merge PR 6: session transport hardening |

All six merge commits are dated 2026-10-25. The change document itself closes at `de9f91a9`
(the verify revision) plus `08471e79` (the four delta corrections).

In one paragraph: the anonymous gate now decides on the canonically parsed **requested page**
(`fs_auth_request::requestedPage()`, mirroring `index.php`) instead of the request *shape*, and it
exempts exactly the pages the application serves anonymously (`PUBLIC_PAGES = ['login',
'password_reset']`); the login CSRF verdict is authoritative and a credential submission is
evaluated once, with a second guard on the FS2025 controller door; remember-me revocation is
scoped to the authenticated session (both unsigned-cookie fallbacks deleted, neutral notice, no
per-account IP disclosure, escaped login message blocks); the password-reset throttle owns its own
bucket (`tmp/<FS_TMP_NAME>password_reset_ip.log`) with the authentication bucket byte-identical,
validation before counting, and CSRF on both reset entry points; the served tree is
deny-by-default at the edge (eight `^~` denies, `themes/` deliberately allowed), the pre-commit
hook rejects an adjacent credential above its early exit, and the redaction sweep removed the
published `admin` credential from the planning tree; and the session cookie attribute set has one
definition applied before every `session_start()`, the duplicate emission is deleted, strict mode
is on at the outermost entry point, and HSTS is emitted on secure responses from all three entry
points.

## Spec Merge Summary

| Delta file | Requirements | Canonical target | Action | Merged |
|---|---|---|---|---|
| `specs/anonymous-access-gate/spec.md` | AAG-01…AAG-06 (6) | `openspec/specs/anonymous-access-gate/spec.md` | **CREATED** | 25 scenarios |
| `specs/login-throttle-isolation/spec.md` | LTI-01…LTI-05 (5) | `openspec/specs/login-throttle-isolation/spec.md` | **CREATED** | 16 scenarios |
| `specs/deployment-surface-hygiene/spec.md` | DSH-01…DSH-05 (5) | `openspec/specs/deployment-surface-hygiene/spec.md` | **CREATED** | 16 scenarios |
| `specs/session-transport-hardening/spec.md` | STH-01…STH-06 (6) | `openspec/specs/session-transport-hardening/spec.md` | **CREATED** | 23 scenarios |
| `specs/remember-me-revocation-authority/spec.md` | RMR-01…RMR-06 (6) | `openspec/specs/remember-me-revocation-authority/spec.md` | **CREATED** | 18 scenarios |
| `specs/login-csrf/spec.md` | LC-06, LC-07 (2 added) | `openspec/specs/login-csrf/spec.md` | **MODIFIED** | 13 scenarios appended |
| `specs/session-consolidation/spec.md` | SC-01 (1 modified) + Known-deviation replacement | `openspec/specs/session-consolidation/spec.md` | **MODIFIED** | 5 scenarios, 1 section replaced |

Totals: **30 requirement IDs added** (28 across the five new capabilities, 2 into `login-csrf`),
**1 existing requirement amended** (SC-01), and **116 scenarios** merged (98 new capability
scenarios, 13 for LC-06/LC-07, 5 for SC-01).

### Promotion mechanics — the five new capabilities

Each delta was already written in full-spec shape (`# <name> Specification` / `## Purpose` /
`## Requirements` table / requirement sections / `## Out of scope`), matching the
`htmx-core-support` precedent. The merge was **mechanical**: the canonical file was composed as the
delta's H1 plus the delta body from `## Purpose` onward, written to a temporary file and moved into
place. The only removed text is each delta's change-scoped preamble (`Change: harden-auth-boundaries`
/ `Finding: VULN-xxxx` / `Design refs:`), which is planning metadata, not specification — no
canonical spec carries that block. Readback after every promotion: the `## Purpose…`-onward body of
the promoted file was `diff`-ed against the same region of the archived delta and returned
**identical** for all five.

### Merge mechanics — `login-csrf` (MODIFIED, Spanish)

The canonical file is Spanish and the delta is Spanish (its own merge note says the canonical file
is not translated and that `LC-02` is **not** redefined). The merge therefore:

- appended two table rows (`LC-06`, `LC-07`) after `LC-05`;
- appended the delta's `### Requirement: LC-06` and `### Requirement: LC-07` sections — extracted
  from the delta with `awk` and appended byte-for-byte (readback `diff` exit 0), rather than
  retyped.

**`LC-02` was not touched** — it remains the exact text the archived `consolidate-session-csrf`
change owns (unified session name, backend-consistent token reads, no mismatch from legacy
`PHPSESSID` cookies). Verified by `diff` of the first 116 lines before/after this merge: unchanged.

### Merge mechanics — `session-consolidation` (MODIFIED, English)

The delta supplies two replacement blocks; both were taken from the delta verbatim (extracted with
`awk`, blank lines normalised only — verified that the non-blank content is identical) and spliced
into the canonical file:

| Block | Applied as |
|---|---|
| New SC-01 requirement row | Table row rewritten to carry the no-residual-deviation clause |
| `### Requirement: SC-01 — Shared session name before session_start()` (body + 5 scenarios) | Inserted where the old `### Known deviation` paragraph stood |
| `### Replaces the … Known deviation … paragraph` | Inserted as `### Closed deviation: residual bare \`session_start()\` fallbacks`, body preserved |

Only the change-scoped merger line `(Previously: SC-01 stated …)` was dropped (it is a merge
instruction, not specification). Everything before line 17 and from the former line 25 onward was
verified unchanged by `diff`. `SC-02…SC-05`, their scenarios, the `Security rationale (do not
revert)` paragraph and both `### Edge Case` blocks are byte-identical to before.

### Deliberately preserved clauses

- **AAG-01's page-not-shape rule** — the exemption is the requested page, never the HTTP method,
  body or `nlogin`; `isLegacyLoginSubmission()` **MUST NOT** participate in the exemption.
- **AAG-02's `LOGIN` / `login%20` split** — the fail-closed half and the mirroring half are separate
  scenarios, with the note explaining that the old pair would have ratified a raw-comparison defect.
- **AAG-06** — `password_reset` is public for render and submission, safe by CSRF plus an isolated
  throttle, never by unreachability.
- **DSH-01's `# nosecret` opt-out** — a reviewed, per-line exception; the adjacency rule is not
  relaxed and the marker cannot silence a real credential.
- **SC-01's residual condition** — `NativeSessionCsrfStorage::ensureSession()` is named with the
  exact shape that would start unnamed (a token operation after a mid-request session close with
  `SessionManager` absent) and the one-line fix required before that shape becomes reachable.
- **STH's normative testing constraint** — no scenario may claim a header assertion the CLI SAPI
  cannot make; the header half stays an operator probe.

### Language decision (recorded)

Measured at archive time: `openspec/specs/login-csrf/` and `openspec/specs/session-integrity/` are
Spanish; `openspec/specs/session-consolidation/` and every canonical spec created since 2026-08 are
English. The two **modified** files kept the language they already used (`login-csrf` Spanish,
`session-consolidation` English); the five **created** capabilities are English, matching the
dominant language of their siblings. No file is bilingual and no canonical file was translated.

## Test Baseline (carried over, not re-measured)

The archive phase changed no implementation byte and no test byte, so the baseline cannot have
moved. Carried from `verify-report.md`:

| Command | Result | Status |
|---|---|---|
| `ddev exec php vendor/bin/phpunit` | **4497 tests, 16796 assertions, 12 failures, 27 skipped** (exit 1) | Carried over |
| `ddev exec php vendor/bin/phpunit --testsuite Security` | **334 tests, 857 assertions, 0 failures, 1 skipped** (exit 0) | Carried over |
| `ddev exec composer phpstan` | **38 errors** — pre-existing, 4 untouched test files, no `ignoreErrors` growth | Carried over |
| change-scoped subset (15 files) | `OK (213 tests, 656 assertions)` | Carried over |

All **12 full-suite failures are in `plugins/OidcProvider`** — a separate git repository this change
never touched (`git log -- plugins/` shows no commit from any of the six PRs). They are pre-existing
DB-fixture / schema-drift failures (8 `admin_oidc_customer_access_htmxTest`, 1
`admin_oidc_customer_access_validation_groupsTest`, 2 `ClienteRoleResolverIntegrationTest`, 1
`migration011_cliente_gruposTest` collation case), not session, CSRF or authorization logic. The
`Security` suite — the one that covers this change — is green with zero failures. No new PHPStan
finding was introduced.

## Findings That Remain OPEN — NOT closed by this archive

**Archiving the SDD closes no finding.** Stated plainly:

1. **VULN-0005 is still OPEN.** The redaction sweep and the nginx deny rules shipped, but the
   credential lives in git history and in the public upstream repository. Only (a) rotating the
   `admin` account's `log_key` and (b) applying the deny rules with an `nginx -t` + reload close it.
   Neither is in this repository. Merging hygiene controls without them leaves the finding open
   while looking fixed — the proposal's risk 6.
2. **VULN-0007's header criteria are unproven.** The PHPUnit CLI SAPI cannot observe `Set-Cookie`
   or `Strict-Transport-Security` (`headers_list()` is `[]`). Those criteria are operator probes
   (O.3, O.4) and were **never recorded as passing** anywhere in this change.
3. **AAG-06 is a stealth-disabled-only fix.** Under `stealth_enabled = 1` the gate answers with the
   public homepage or the secret-entry redirect before reaching the exemption, so the recovery page
   needs the secret — exactly like `login`. Documented at `tasks.md` (Known scope limit), not a
   defect, but not a fix under stealth either.
4. **Post-authentication object-level authorization was never tested.** No credentials were
   available; the scan covers the anonymous and pre-authentication surface only. Out of scope,
   unclaimed.
5. **The PR 4 live retest proved reachability, not counter isolation.** All six anonymous reset
   POSTs returned `302 → login` and the authentication bucket was byte-identical before and after,
   which proves the PoC no longer reaches the handler. Counter isolation rests on the in-process
   suite. The `tasks.md:321` checkbox stays open by design.
6. **Latent, carried:** `CsrfManager::$tokenVerified` is never invalidated within a request (D18,
   named owner: a `session-integrity` follow-up), and the third page-canonicalisation copy in
   `fs_maintenance_mode::isBackendPageRequest()` remains divergent (D19, named owner).

## Operator Handoff — carried out unticked (O.1–O.6)

None of these is achievable in this repository and none is reverted by a code revert. They are
carried here exactly as recorded in `tasks.md`, **unticked**:

| # | Action | Retest | Closes |
|---|---|---|---|
| **O.1** | Apply the extended `nginx-sample.conf` deny rules on the live server and reload nginx | `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`; `GET /config.php` → `404`; `nginx -t` exits `0` | VULN-0005 (half) |
| **O.2** | **Rotate the `admin` account's `log_key`** (the published `auth_sig` is burned and already public) | The published triple no longer appears in any served document; the key differs from the published value | VULN-0005 (the only step that closes it) |
| **O.3** | Live `Set-Cookie` probe on a secure response | Exactly **one** session cookie, carrying `Secure`, `HttpOnly`, `SameSite=Lax` | STH-02 / VULN-0007 |
| **O.4** | Live HSTS probe | `Strict-Transport-Security: max-age=31536000`, no `includeSubDomains`; absent with `FS_HSTS_MAX_AGE=0` | STH-04 / VULN-0007 |
| **O.5** | Live remember-me failure probe | Anonymous `GET ?page=login` with `user=<real nick>` + bogus `logkey`/`auth_sig` deletes only the caller's own cookies; the named account's `fs_users.log_key` is unchanged in the DB | RMR-02 / VULN-0008 |
| **O.6** | Re-run the strix scan recipe (FIX-PLAN §6) | Six core findings closed, no new finding in the touched surfaces | Whole change |

Deployment note carried: set `FS_HSTS_MAX_AGE=0` on development hosts before deploying PR 6 — an
HSTS `max-age` already served to a browser is not undone by a code revert.

## Archive Method & Mechanical Readback

- **Change folder move**: recursive snapshot to `/tmp/opencode/hab-snapshot/` (12 files, taken
  **after** the verify follow-up was appended) → `git mv openspec/changes/harden-auth-boundaries
  openspec/changes/archive/2026-10-25-harden-auth-boundaries` → `diff -r` snapshot vs destination:
  **empty (exit 0)**. Counts match on both sides: 12 files, including `proposal.md`, `design.md`,
  `apply-progress.md`, `tasks.md`, `verify-report.md` and the seven delta specs.
- **Naming convention**: `YYYY-MM-DD-{change-name}`, matching every existing entry in
  `openspec/changes/archive/` and the same-day `2026-10-25-consolidate-session-csrf`.
- `archive-report.md` is additive and did not exist in the source snapshot.
- **No entry for this change remains in `openspec/changes/` outside `archive/`** — confirmed:
  `openspec/changes/harden-auth-boundaries/` no longer exists, no active change directory carries
  this name, and the only occurrence of the name outside `archive/` is a prose reference from other
  changes' planning documents.

### Files this archive phase touched

| File | Action |
|---|---|
| `openspec/changes/archive/2026-10-25-harden-auth-boundaries/` | **MOVED** (12 files, byte-identical) |
| `openspec/changes/archive/2026-10-25-harden-auth-boundaries/verify-report.md` | **APPENDED** — "Verify follow-up" section only; verdict and findings untouched |
| `openspec/changes/archive/2026-10-25-harden-auth-boundaries/archive-report.md` | **CREATED** |
| `openspec/specs/anonymous-access-gate/spec.md` | **CREATED** |
| `openspec/specs/login-throttle-isolation/spec.md` | **CREATED** |
| `openspec/specs/deployment-surface-hygiene/spec.md` | **CREATED** |
| `openspec/specs/session-transport-hardening/spec.md` | **CREATED** |
| `openspec/specs/remember-me-revocation-authority/spec.md` | **CREATED** |
| `openspec/specs/login-csrf/spec.md` | **MODIFIED** — LC-06, LC-07 added; LC-02 untouched |
| `openspec/specs/session-consolidation/spec.md` | **MODIFIED** — SC-01 row + Known-deviation paragraph replaced |

## Constraints Honoured

- No implementation code and no test was modified.
- Nothing under `plugins/` was touched.
- The archived `consolidate-session-csrf` directory was not touched.
- The archived `proposal.md`, `design.md`, `tasks.md` and `apply-progress.md` were not edited; the
  six unchecked obligations were carried, not ticked.
- The only pre-existing file modified is `verify-report.md`, and only by appending the follow-up
  section — the original verdict, findings and conclusion remain intact above it.
- No secret literal was introduced: the merged text contains only the `FSSESS_xxx` prose placeholder
  and `<redacted>` markers.

## SDD Cycle Complete

The change is archived and its requirements are the canonical source of truth: five new
capabilities (`anonymous-access-gate`, `login-throttle-isolation`, `deployment-surface-hygiene`,
`session-transport-hardening`, `remember-me-revocation-authority`), two added requirements in the
existing `login-csrf` capability (LC-06, LC-07), and SC-01 plus its deviation record rewritten in
`session-consolidation`. The implementation was already live and green; this archive closes the
documentation gap that the independent verify run opened and that the four corrections resolved —
and it closes **no security finding**. VULN-0005 stays open until O.1 and O.2 are confirmed;
VULN-0007's header criteria stay unproven until O.3 and O.4 are run.
