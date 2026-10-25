# deployment-surface-hygiene Specification

## Purpose

Two independent exposures share one root cause: the served surface is not limited to
runtime assets, and the edge rules that would deny the rest are inert and do not cover
the planning tree. This capability makes the served tree deny-by-default at the edge,
removes credential material from every served document, and installs a pre-commit control
that rejects a pasted credential before it can be committed.

**Closure is operator-dependent.** Redaction and deny rules are hygiene: the published
value is already in git history and in the public upstream repository. The finding is
closed only by (a) applying the extended deny rules on the live server and reloading
nginx, and (b) rotating the `admin` account's `log_key`. Until both are confirmed,
VULN-0005 **MUST** be reported as open.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| DSH-01 | Credential material under the served tree **MUST** be described, never reproduced | MUST |
| DSH-02 | The edge deny rules **MUST** cover every non-runtime directory, and **MUST NOT** deny browser-fetched theme assets | MUST |
| DSH-03 | A pre-commit secret scan **MUST** reject a staged credential, using an adjacency rule, before the hook's early exit | MUST |
| DSH-04 | The redaction invariant **MUST** be enforced by an automated repository scan in the test suite | MUST |
| DSH-05 | The operator handoff **MUST** be recorded as an unverified gate with its retest recipe, and the finding **MUST** stay open until confirmed | MUST |

### Requirement: DSH-01 — Credential material is described, never reproduced

No document under the served tree **MUST** contain a literal credential value. A
credential value is a 40- or 64-character hexadecimal blob **adjacent** to one of the
tokens `logkey`, `auth_sig`, `password` or `token`, or a literal session identifier /
CSRF token value.

A line carrying the documented inline opt-out marker (`# nosecret` or `// nosecret`, the
same marker DSH-03 authorises) is **not** a finding: the marker records a reviewed,
non-credential value on that line. Three lines use it today — the SHA-1-of-the-empty-string
fixtures in `tests/Security/CookieSecureAndPasswordMigrationTest.php` (`:73`, `:96`,
`:115`), each a public legacy hash written beside a `password` property. The opt-out is a
reviewed, per-line exception, never a relaxation of the rule: it **MUST NOT** be used to
silence a real credential, and it does not replace the adjacency test (a matching line
without the marker is still a finding).

> *(Amended during verify.)* The definition originally carried no opt-out, so those three
> fixtures were literal violations of a requirement that DSH-03 and the shipped scan both
> exempt. Naming the marker is what the implemented control does; weakening the adjacency
> rule would have been the alternative, and it is the wrong one.

Redaction **MUST** replace the value with a placeholder such as `<redacted>` while
preserving the surrounding narrative, so the document keeps its evidentiary value.
The reference style is the already-redacted
`openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md`.

The detection rule **MUST** be adjacency-based. A rule based on "any 40/64-character hex
blob" **MUST NOT** be used: `tests/bootstrap.php:62` defines a deterministic 64-hex
`FS_SECRET_KEY` that is not adjacent to any of the four tokens, and a non-adjacency rule
would produce a false positive that forces the control to be weakened or ignored. The
per-line opt-out does not relax this requirement: it suppresses a *reviewed adjacency
match* and says so on the line, whereas a non-adjacency rule would match values the rule
was never meant to cover and leave no reviewable record at all.

#### Scenario: The published credential no longer exists under the served tree

- GIVEN the repository at the change's revision
- WHEN every tracked, non-vendor path is scanned for a 40/64-hex blob adjacent to `logkey=`, `logkey:`, `auth_sig=`, `auth_sig:`, `password=` or `token=`
- THEN no match is reported
- AND `openspec/changes/ventas-clientes-controller-dedup/verify-report.md` contains no literal `logkey`, `auth_sig`, session identifier or CSRF-token value

#### Scenario: The deterministic test secret is not a false positive

- GIVEN `tests/bootstrap.php`'s deterministic 64-hex `FS_SECRET_KEY`
- WHEN the adjacency rule is applied to that file
- THEN no match is reported
- AND the control is not weakened to accommodate it

#### Scenario: The reviewed legacy-hash fixtures are exempt through the documented opt-out

- GIVEN `tests/Security/CookieSecureAndPasswordMigrationTest.php`, whose three fixtures write the SHA-1 of the empty string beside a `password` property
- WHEN the adjacency rule is applied to each of those lines
- THEN each line carries the `# nosecret` marker and no finding is reported for it
- AND a line matching the adjacency rule without the marker is still reported as a finding

#### Scenario: Prose placeholders are not redacted away

- GIVEN planning documents that mention the cookie names in prose (for example a `FSSESS_xxx` placeholder)
- WHEN the redaction sweep is applied
- THEN literal values are redacted and placeholder prose is left intact

#### Scenario: The narrative keeps its evidentiary value

- GIVEN the redacted credential-bearing report
- WHEN it is read
- THEN the request shape, the code path and the conclusion are still stated
- AND only the literal secret is replaced

### Requirement: DSH-02 — Deny-by-default at the edge covers the non-runtime surface

`nginx-sample.conf` **MUST** contain a `^~` prefix deny location for each of the eight
directories that exist in the web root and hold no runtime assets: `openspec/`, `docs/`,
`tools/`, `extras/`, `odd/`, `dev-tools/`, `managed_context/`, `test_suite_analysis/`.

`^~` prefix locations **MUST** be used (not regex locations): they are matched on the
normalised, decoded URI and take precedence over the generic `location ~ \.php$` handler,
which is the mechanism the file's existing sensitive-directory rules already rely on, and
it closes the whole class rather than a single document.

`themes/` **MUST NOT** be denied: it holds browser-fetched CSS/JS. The existing exact-file
denies and prefix denies **MUST** remain in force.

#### Scenario: Every uncovered non-runtime directory is denied

- GIVEN the extended `nginx-sample.conf`
- WHEN the file is parsed for `^~` deny locations
- THEN each of `openspec/ docs/ tools/ extras/ odd/ dev-tools/ managed_context/ test_suite_analysis/` has one

#### Scenario: Browser-fetched theme assets are not denied

- GIVEN the extended `nginx-sample.conf`
- WHEN the file is parsed for deny locations
- THEN it contains no deny location for `themes/`

#### Scenario: The pre-existing deny layer is preserved

- GIVEN the extended `nginx-sample.conf`
- WHEN the file is compared with its previous revision
- THEN the exact-file denies (`config.php`, `cron.php`, `debug_loader.php`, `visual_flow_demo.php`, `emergency_admin_reset.php`, `reset_admin_password.php`) and the existing prefix denies are unchanged

### Requirement: DSH-03 — A pre-commit secret scan rejects a pasted credential

`scripts/hooks/pre-commit` **MUST** run a secret scan over staged files using the DSH-01
adjacency rule, and **MUST** exit non-zero with the offending file and line when a match
is found. An inline `# nosecret` marker **MUST** opt a line out.

The scan **MUST** run **before** the hook's `composer.json` early exit. Today the hook
exits `0` whenever `composer.json` is not staged, so a scan appended after that block
would never run for a normal commit.

#### Scenario: A staged credential is rejected

- GIVEN a staged fixture containing `logkey=<64 hex>`
- WHEN the pre-commit hook runs
- THEN it exits non-zero
- AND it reports the offending file and line

#### Scenario: The scan runs before the early exit

- GIVEN a commit that stages only `composer.json` (so the Composer block early-exits)
- WHEN the hook runs
- THEN the secret scan still executed over the staged files
- AND the commit is not blocked by the scan when no credential is present

#### Scenario: The opt-out marker works

- GIVEN a staged fixture containing a credential-shaped line carrying the `# nosecret` marker
- WHEN the hook runs
- THEN the line is not reported and the hook does not fail because of it

### Requirement: DSH-04 — The redaction invariant is enforced by a scan in the suite

The DSH-01 invariant **MUST** be enforced by an automated repository scan in the test
suite (a `ServedTreeSecretScanTest`-class test over tracked non-vendor paths), so that a
future verification report that pastes a real credential fails the suite instead of
reaching a deployment. The test **MUST** document the `tests/bootstrap.php` near-miss
inline so the adjacency rule is not later "simplified" into a non-discriminating one.

#### Scenario: The scan goes red on a pasted credential

- GIVEN the scan test and a temporary fixture containing `auth_sig=<64 hex>` under the served tree
- WHEN the scan test runs
- THEN it fails, naming the fixture
- AND removing the fixture makes it pass again

#### Scenario: The scan is green on the shipped tree

- GIVEN the repository at the change's revision
- WHEN the scan test runs as part of the suite
- THEN it passes
- AND its assertions target the adjacency rule, not a bare 64-hex pattern

### Requirement: DSH-05 — Operator handoff is an unverified gate, not a passed one

The change **MUST** record the deployment actions it cannot perform as an explicit
operator handoff, with the exact retest recipe, and VULN-0005 **MUST** be reported as
open until both actions are confirmed:

1. Apply the extended deny rules on the live server and reload nginx; retest
   `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403` and
   `GET /config.php` → `404`; `nginx -t` exits `0`.
2. Rotate the `admin` account's `log_key` (the published `auth_sig` is burned), and
   confirm the published triple no longer appears in any served document and that the
   account's key differs from the published value.

Neither action is achievable in this repository, and neither is undone by a code revert
(a rotated key stays rotated, which is the desired direction).

#### Scenario: The retest recipe is recorded with the change

- GIVEN the change's verification artifacts
- WHEN they are read
- THEN both operator actions are listed with their exact retest commands
- AND they are marked as unverified gates owed by the operator

#### Scenario: A code-only merge does not claim closure

- GIVEN a revision where redaction, deny rules and the hook are merged but the operator actions are unconfirmed
- WHEN the finding status is reported
- THEN VULN-0005 is reported **open**
- AND the reason given is the missing rotation and nginx reload, not a code defect

#### Scenario: The deny rules are not verifiable here

- GIVEN that nothing in the repository executes `nginx-sample.conf`
- WHEN the change's verification is reported
- THEN the nginx rules are recorded as a text-level artifact validated by a parser test
- AND `nginx -t` plus a live request are recorded as owed by the operator

## Out of scope (explicit)

- **Deleting the planning tree from the deployment**, or relocating the document root to
  runtime assets only. The correct long-term fix, explicitly deferred by the proposal;
  this capability ships the deny rules that make the current layout safe.
- **Rewriting git history to purge the credential.** The value is in the public upstream
  repository; rotation is the closure and history rewriting buys nothing.
- **Denying `openspec/` with a regex location.** Rejected: `^~` is matched on the
  normalised, decoded URI and takes precedence over the PHP handler.
- **A server-side (CI/remote) secret scan.** Out of reach in this repository; the local
  hook is defense in depth and the redaction sweep is the primary control.
