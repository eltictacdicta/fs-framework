# Apply progress — harden-auth-boundaries

## Slice 5 of 6 — W5: deployment surface hygiene (VULN-0005, DSH-01…DSH-05)

- **Branch**: `fix/harden-auth-boundaries-w5`
- **Base**: `39449170` (`Merge PR 4: isolate the password-reset throttle and make recovery reachable`)
- **Units**: 6 commits, one file each (see below)
- **Status**: **code complete and verified. VULN-0005 stays OPEN** — it cannot be closed
  in this repository (see "Operator handoff").

`tasks.md`, `design.md`, `proposal.md` and `specs/` are **not** committed by this slice;
only the checkboxes in `tasks.md` and this file were updated in the working tree.

## Work units

Internal order is load-bearing: the pre-commit control landed **before** the redaction
sweep, so the sweep cannot be undone by the next commit.

| # | Commit | File | Result |
|---|--------|------|--------|
| U5.1 | `2fc298c4` | `tests/Security/ServedTreeSecretScanTest.php` | **[TEST] RED** — 3 failures; named `ventas-clientes-controller-dedup/verify-report.md:62` plus three pre-existing SHA-1-of-empty-string fixtures |
| U5.2 | `d968110a` | `scripts/hooks/pre-commit` | Secret scan inserted **above** the `composer.json` early exit; staged files only; `# nosecret` opt-out; value never echoed |
| U5.3 | `45154fd3` | the two `ventas-clientes-*/verify-report.md` + 3 `# nosecret` markers | Redaction sweep; scan test green |
| U5.4 | `c7acb59b` | `tests/Core/NginxDenyRulesTest.php` | **[TEST] RED** — 2 failures (eight directories missing; deny set mismatch) |
| U5.5 | `ea41f7a9` | `nginx-sample.conf` | Eight `^~` deny locations grouped with the sensitive-directory block; `themes/` untouched |
| U5.6 | `5e471af0` | `tests/Core/PreCommitSecretScanTest.php` | Hook driven against a temporary `GIT_INDEX_FILE`; GREEN. Verified RED against the previous hook (2 of 4 cases fail) |

## Verification

| Command | Result |
|---|---|
| `phpunit --filter ServedTreeSecretScanTest` | 6 tests, 27 assertions, 0 failures |
| `phpunit --filter NginxDenyRulesTest` | 7 tests, 71 assertions, 0 failures |
| `phpunit --filter PreCommitSecretScanTest` | 4 tests, 14 assertions, 0 failures |
| `phpunit --testsuite Security` | 319 tests, 0 failures, 1 skipped (baseline 313 + 6) |
| `phpunit --testsuite Core` | 455 tests, 0 failures |
| `phpunit` (full) | `Tests: 4476, Assertions: 16717, Failures: 12, Skipped: 27` — all 12 in `plugins/OidcProvider` (pre-existing, separate repository) |
| `composer phpstan` | 38 findings, exactly the 4 pre-existing untouched test files; no new finding |
| `nginx -t` (sample embedded in a throwaway `server {}` inside the web container) | `syntax is ok` / `test is successful` |

The `nginx -t` run validates the **syntax of the sample**, not the deployment. The sample
has no full server block, so the wrapper supplied `events {}`, `http {}`, `server {}` and
`pid /tmp/nginx-w5.pid`.

### Documented fallback status (U5.2 / U5.6)

The temporary-`GIT_INDEX_FILE` harness **worked**; the manual fallback documented in
`tasks.md` was **not** needed and was not used.

## Requirement status

| ID | Status | How it is known |
|----|--------|-----------------|
| DSH-01 | **Satisfied in the served tree, with one residual outside this slice's write scope** | The adjacency scan is green tree-wide; both `ventas-clientes-*` reports carry no literal `logkey`/`auth_sig`/session id/CSRF value while keeping the request shape, the code path and the conclusion; `tests/bootstrap.php` is untouched. Residual: `tasks.md:359` and `design.md:246` still quote the literal session id (see Risks). |
| DSH-02 | **Satisfied (text-level)** | `NginxDenyRulesTest` pins the eight `^~` denies, the absence of any `themes/` deny, the unchanged exact-file 404s/prefix denies, and set equality. Live behaviour is O.1. |
| DSH-03 | **Satisfied** | `PreCommitSecretScanTest` runs the real hook over a temporary index: credential → non-zero with `file:line` and no value echoed; `# nosecret` → 0; clean → 0; the early-exit path → non-zero. |
| DSH-04 | **Satisfied** | `ServedTreeSecretScanTest` goes red on a fixture placed in the served tree and green once removed; it goes red on the shipped tree **before** U5.3 and green after; the `tests/bootstrap.php` near-miss is pinned inline by a dedicated case. |
| DSH-05 | **Satisfied as a recorded gate; not closed** | O.1 and O.2 are recorded below and in `tasks.md` as **owed**. VULN-0005 is reported **OPEN**. |

## Operator handoff — OWED, not done

Neither action is achievable in this repository. **Neither is undone by a code revert**
(a rotated key stays rotated, which is the desired direction).

| # | Action | Status | Retest |
|---|--------|--------|--------|
| O.1 | Apply the extended `nginx-sample.conf` deny rules on the live server and reload nginx | **OWED** | `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`; `GET /config.php` → `404`; `nginx -t` exits `0` |
| O.2 | Rotate the `admin` account's `log_key` | **OWED** | The published triple no longer appears in any served document; the account's key differs from the published value |
| O.6 | Re-run the strix scan recipe | **OWED** (whole change) | Six core findings closed, no new finding |

**VULN-0005 MUST be reported OPEN until O.1 and O.2 are confirmed.** Redaction and deny
rules are hygiene: the credential is already in git history and in the public upstream
repository. Only the rotation closes it.

## Risks / residual items

1. **Literal session identifier in this change's own artifacts.** `tasks.md:359` and
   `design.md:246` quote `FSSESS_<redacted>` while describing the sweep. This slice may
   only update checkboxes there, so both must be rewritten to `FSSESS_<redacted>=<redacted>`
   before archive — otherwise DSH-01 is violated by the change's own documents. The suite
   scan is deliberately scoped to the two `ventas-clientes-*` reports (the document DSH-01
   scenario 1 names) and does not police this change's planning prose.
2. **A second pre-existing adjacency match, resolved with the documented opt-out.** The
   SHA-1 of the empty string appears as a legacy-password fixture at
   `tests/Security/CookieSecureAndPasswordMigrationTest.php:73,96,115`. Each line now carries
   `// nosecret`. The rule was **not** relaxed.
3. **Slice size.** 1 015 insertions across 8 files — above the 800-line session budget in
   `tasks.md` (forecast ~190) but in family with this chain (PR 4 shipped 1 306). No
   `size:exception` was used. If the reviewer wants it under 800, the cheapest trim is to
   collapse the four nginx contract tests into data-provider cases (~−120), shorten the
   `ServedTreeSecretScanTest` docblocks (~−60) and the hook header (~−20).
4. **The hook is local defense in depth.** It is a tracked script symlinked into
   `.git/hooks`, not a server-side check; a contributor who bypasses hooks is not stopped.
   The redaction sweep plus O.2 remain the primary controls.

## Rollback boundary

Revert the six commits independently: the hook (`d968110a`), the config hunk
(`ea41f7a9`), the two document edits and the three `# nosecret` markers (`45154fd3`), and
the three test files (whose removal only removes the controls' evidence, not the controls).
A rotated `admin` key stays rotated. No PHP runtime path, no schema, no session state and
no interaction with the other workstreams.
