# anonymous-access-gate Specification

Change: `harden-auth-boundaries` — NEW capability. This spec becomes the source of
truth at archive time (no existing canonical spec to modify).

Finding: **VULN-0002** (CWE-863, CVSS 6.5) — the global anonymous gate exempts a
request because of its *shape* (HTTP method + body) instead of the *requested page*,
and separately authorises on `Request::getPathInfo()` while `index.php` dispatches on
`$_GET['page']`.

Design refs: D1, D2, D3; W1 of `design.md`; proposal "VULN-0002".

## Purpose

The global anonymous gate (`PublicAccessGate::intercept()`, constructed once per
request at `index.php:140`, before Symfony routing and every controller) is the first
authorization decision in the application. This capability binds that decision to the
fact it must depend on — the canonically parsed **requested page** — so that a login
form can only be submitted to `index.php?page=login`, and so that the gate and the
dispatcher cannot disagree about which page a request names.

Two structural defects are closed together because they are the same gate, the same
file and the same root cause (design D3):

1. The exemption consulted `isLegacyLoginSubmission()`, which inspects only the HTTP
   method and the body — so `POST /index.php?page=admin_info&nlogin=1` (or the same
   POST with `user`/`password`) skipped the gate and executed the controller's
   anonymous path. `password_reset::process_request()` was reached this way.
2. The public-path allowlist is evaluated against `getPathInfo()` while dispatch uses
   `page=`, so `POST /index.php/favicon.ico?page=password_reset` presented an
   allowlisted path to the gate and still reached a private controller.

The "login submission" predicate is additionally consolidated into one
dependency-free definition, because today it exists in two copies
(`src/Core/StealthMode.php` and `base/fs_maintenance_mode.php`) that can drift.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| AAG-01 | The anonymous exemption **MUST** depend on the requested page, never on the request shape | MUST |
| AAG-02 | The requested-page fact **MUST** be derived by one canonical rule that mirrors the dispatcher's own rule | MUST |
| AAG-03 | Non-string `page` input (e.g. `?page[]=login`) **MUST** be treated as "no page" and **MUST NOT** be fatal | MUST |
| AAG-04 | There **MUST** be exactly one "login submission" predicate, shared by every consumer | MUST |
| AAG-05 | The gate and the dispatcher **MUST** agree on which page a request names (public-path and browser-probe decisions included) | MUST |

### Requirement: AAG-01 — The anonymous exemption depends on the requested page

`PublicAccessGate::intercept()` **MUST** exempt an anonymous request from the gate only
when the request's canonical requested page is `login`. It **MUST NOT** exempt a request
because of its HTTP method, its body, or the presence of `nlogin`. The exemption
condition is `isLegacyLoginRequest()` alone; `isLegacyLoginSubmission()` **MUST NOT**
participate in the exemption decision (it stays in use where the stealth secret has
already been verified with `hash_equals()`, so it does not become dead code).

Any anonymous request that is not the login page **MUST** fall through to the anonymous
login redirect (`302` to `index.php?page=login`), for every page of the application, and
the target controller **MUST NOT** be instantiated.

#### Scenario: Anonymous POST with `?nlogin` to a non-login page is gated

- GIVEN an anonymous request with `POST /index.php?page=admin_info&nlogin=1` and no credentials
- WHEN `PublicAccessGate::intercept()` evaluates the request
- THEN it returns a response with status `302`
- AND the `Location` header is `index.php?page=login`
- AND `admin_info` is not instantiated

#### Scenario: Anonymous POST with a credential body to a non-login page is gated

- GIVEN an anonymous `POST /index.php?page=admin_info` whose body carries `user` and `password`
- WHEN `PublicAccessGate::intercept()` evaluates the request
- THEN it returns a response with status `302` and `Location` `index.php?page=login`

#### Scenario: Every non-login controller page is gated for both bypass shapes

- GIVEN a table of non-login page names covering the administrative and plugin surfaces
- WHEN each page is requested anonymously as `POST ?page=<name>&nlogin=1`, and again as `POST ?page=<name>` with `user`/`password`
- THEN every response is a `302` to `index.php?page=login`
- AND no response is a `200` carrying a controller body

#### Scenario: The protected reset handler is no longer reachable anonymously

- GIVEN an anonymous `POST /index.php?page=password_reset&nlogin=1`
- WHEN the gate evaluates the request
- THEN the response is a `302` to `index.php?page=login`
- AND the response body contains neither the reset-request validation message nor the reset throttle message, i.e. `password_reset::process_request()` did not execute

#### Scenario: A legitimate login submission is still exempt

- GIVEN an anonymous `POST /index.php?page=login` carrying `nlogin` and/or `user`/`password`
- WHEN `PublicAccessGate::intercept()` evaluates the request
- THEN it returns `null` (not intercepted) and the login controller is reached, unchanged from today

#### Scenario: The login page render is still exempt

- GIVEN an anonymous `GET /index.php?page=login` with stealth disabled
- WHEN `PublicAccessGate::intercept()` evaluates the request
- THEN it returns `null` (not intercepted)

### Requirement: AAG-02 — One canonical requested-page rule, mirroring the dispatcher

The requested-page fact **MUST** be produced by a single dependency-free definition
(`fs_auth_request::requestedPage(array $query): string`) that applies exactly the rule
`index.php::fs_get_requested_page_name()` already applies: reject a non-string; `trim()`;
accept only a match of `/^[a-z][a-z0-9_]*$/`; otherwise return the empty string.

The gate **MUST NOT** compare the raw `$_GET['page']` value. A raw comparison would make
the gate and the dispatcher disagree again for `page=login%20` (the dispatcher resolves
`login`; a raw comparison would see a non-login page and redirect a legitimate submission
away), which is the defect this capability exists to remove.

A non-canonical page value **MUST** be fail-closed: the request is gated and the redirect
still lands on the real login page.

#### Scenario: Canonical page values resolve as the dispatcher resolves them

- GIVEN the query arrays in the table below
- WHEN `fs_auth_request::requestedPage()` is called for each
- THEN it returns exactly the mapped value

| Input `$query` | Return |
|---|---|
| `['page' => 'login']` | `'login'` |
| `['page' => 'login ']` | `'login'` |
| `['page' => 'admin_info']` | `'admin_info'` |
| `['page' => 'LOGIN']` | `''` |
| `['page' => '']` | `''` |
| `['page' => '9lives']` | `''` |
| `[]` (absent) | `''` |

#### Scenario: Non-canonical page values are gated, not exempted

- GIVEN an anonymous request naming `?page=LOGIN` and another naming `?page=login%20`
- WHEN the gate evaluates each request
- THEN both return a `302` whose `Location` is the real login page
- AND neither is treated as a login page by the gate

#### Scenario: The gate consumes the canonical rule, not the raw query value

- GIVEN a request whose `page` value differs from its canonical form (trailing space)
- WHEN `StealthMode::isLegacyLoginRequest()` is evaluated
- THEN the decision is derived from `fs_auth_request::requestedPage()`
- AND no code path in the gate compares the raw query value

### Requirement: AAG-03 — Non-string `page` input is never fatal

Any `page` input that is not a string (array input such as `?page[]=login`, or any other
non-string shape) **MUST** be treated as "no page requested" and the request **MUST** be
gated. The canonicaliser **MUST NOT** pass a non-string value to `trim()`, `preg_match()`
or any string function: in PHP 8 that is a `TypeError` and would turn the gate into a
`500`, converting an access-control decision into an availability defect.

This is the single most likely new bug introduced by narrowing the exemption (design
Risk 1, severity High) and it is therefore a requirement, not a test detail.

#### Scenario: Array page input is gated without a fatal error

- GIVEN an anonymous `POST /index.php?page[]=login&nlogin=1`
- WHEN the gate evaluates the request
- THEN the response is a `302` to `index.php?page=login`
- AND no `TypeError`, warning or `500` is produced

#### Scenario: The canonicaliser returns the empty string for non-string input

- GIVEN the query arrays `['page' => ['login']]` and `['page' => null]`
- WHEN `fs_auth_request::requestedPage()` is called for each
- THEN each call returns `''` and raises no error

#### Scenario: A non-string page cannot be exempted even with a credential body

- GIVEN an anonymous `POST /index.php?page[]=login` carrying `user` and `password`
- WHEN the gate evaluates the request
- THEN the response is a `302` to `index.php?page=login`

### Requirement: AAG-04 — One shared login-submission predicate

The "login submission" predicate **MUST** have exactly one definition, in a
dependency-free location loadable by both `src/Core/StealthMode.php` and
`base/fs_maintenance_mode.php` without a load-order hazard (`fs_auth_request`).
`StealthMode::isHiddenLoginSubmission()` and
`fs_maintenance_mode::isStealthLoginSubmission()` **MUST** both delegate to it and
**MUST NOT** keep a local copy.

The consolidation **MUST** preserve semantics exactly: method is `POST`, and either
`nlogin` is present in the query or the body carries `user` with (`password` or `email`).
The public contract of `fs_maintenance_mode::hasStealthAdminAccess()` **MUST** be
unchanged (the `system_updater` plugin consumes it through
`lib/maintenance_mode_compat.php`, outside this repository's gates), and every call that
today sits behind a verified stealth secret (`hash_equals()`) **MUST** keep its
behaviour.

#### Scenario: Both consumers resolve the predicate from the single definition

- GIVEN a `POST` whose body carries `user` and `password`
- WHEN `StealthMode::isHiddenLoginSubmission()` and `fs_maintenance_mode::isStealthLoginSubmission()` are evaluated
- THEN both return the same value
- AND neither class contains a local implementation of the predicate

#### Scenario: Stealth admin access behaviour is preserved

- GIVEN a stealth login submission behind a verified stealth secret
- WHEN `hasStealthAdminAccess()` is evaluated
- THEN it returns the same value as before the consolidation
- AND `tests/Base/FsMaintenanceModeTest.php` cases `testLoginSubmissionShowsMaintenance` and `testStealthLoginRequestSkipsMaintenance` stay green

#### Scenario: The submission predicate stays reachable outside the exemption

- GIVEN a valid stealth secret request carrying `nlogin`
- WHEN `StealthMode::isLegacyLoginSubmission()` is evaluated
- THEN it returns `true`
- AND `isLegacyLoginSubmission()` is still referenced by the stealth access path, not only by the gate

### Requirement: AAG-05 — Gate and dispatcher agree on the named page

A framework exact public path (`/favicon.ico`, `/apple-touch-icon*.png`) **MUST** be
exempt only when the request does not name a controller page. When the request names a
controller page, the gate **MUST** treat the request as a page request regardless of the
path it presents.

Plugin-registered public prefixes (e.g. `/oauth`, `/.well-known`) **MUST** stay exempt
unconditionally: they are opt-in by the plugin that owns the route, and narrowing them
would risk unrelated regressions for no security gain.

The same interpretation mismatch in `Plugins::shouldRespondNoContentForBrowserProbe()`
**MUST** be closed: a `204` short-circuit **MUST** require that the request names no
controller page. The framework exact-path test **MUST** be a single extracted definition
consumed by every site that needs it, not a duplicated `in_array(...)`.

#### Scenario: A public path carrying a private page is gated (POST)

- GIVEN an anonymous `POST /index.php/favicon.ico?page=password_reset`
- WHEN the gate evaluates the request
- THEN the response is a `302` to `index.php?page=login` (previously `200`)

#### Scenario: A public path carrying a private page is gated (GET)

- GIVEN an anonymous `GET /index.php/favicon.ico?page=password_reset`
- WHEN the gate and the browser-probe short-circuit are evaluated
- THEN the response is a `302` to `index.php?page=login` (previously `204`)

#### Scenario: A browser probe without a page keeps its 204

- GIVEN an anonymous `GET /index.php/favicon.ico` with no `page` parameter
- WHEN the gate and the browser-probe short-circuit are evaluated
- THEN the response is `204`, unchanged from today

#### Scenario: Plugin public prefixes are unaffected by a page parameter

- GIVEN a request to a plugin-registered public prefix such as `/oauth/callback`, with and without a `page` parameter
- WHEN the gate evaluates each request
- THEN neither is intercepted

#### Scenario: The framework exact-path test has one definition

- GIVEN the `FRAMEWORK_EXACT_PUBLIC_PATHS` constant
- WHEN `Plugins::isPublicPath()` and `Plugins::shouldRespondNoContentForBrowserProbe()` evaluate a framework exact path
- THEN both call the same extracted predicate
- AND the allowlist literal is not duplicated at a second call site

## Out of scope (explicit)

- **The third page-canonicalisation copy** in
  `fs_maintenance_mode::isBackendPageRequest()` (design D19). It diverges
  (`page=ADMIN_HOME` is a backend page there and an invalid page to `index.php`) but no
  finding attaches to it; adopting the strict rule there would change which pages skip
  maintenance mode. Deferred with a named owner, not silently dropped.
- **Widening the plugin-prefix allowlist.** Rejected by design D3.
- **Canonicalising inside `index.php` only.** Rejected: `index.php` is not the only
  dispatcher (`api.php`, `cron.php`, modern routes) and the gate must decide before any of
  them run.
