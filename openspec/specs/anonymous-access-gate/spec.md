# anonymous-access-gate Specification

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
| AAG-06 | `password_reset` **MUST** be a public page — its render and its submission are exempt — and the flow **MUST** be made safe by CSRF plus an isolated throttle, never by unreachability | MUST |

### Requirement: AAG-01 — The anonymous exemption depends on the requested page

`PublicAccessGate::intercept()` **MUST** exempt an anonymous request from the gate only
when the request's canonical requested page is one the application serves anonymously — the
publicly served page set defined by AAG-06 (`login` and `password_reset`, enumerated once
in `fs_auth_request::PUBLIC_PAGES`). It **MUST NOT** exempt a request because of its HTTP
method, its body, or the presence of `nlogin`. The exemption condition is the requested
page alone — `isLegacyLoginRequest()` for the login page, plus
`fs_auth_request::isPublicPage(requestedPage(...))` for the rest of the set;
`isLegacyLoginSubmission()` **MUST NOT** participate in the exemption decision (it stays
in use where the stealth secret has already been verified with `hash_equals()`, so it does
not become dead code).

Any anonymous request naming no page in that set **MUST** fall through to the anonymous
login redirect (`302` to `index.php?page=login`), for every page of the application, and
the target controller **MUST NOT** be instantiated.

> *(Amended during verify.)* The body originally read "only when the request's canonical
> requested page is `login`". That was true when AAG-01 was written; AAG-06 later made
> `password_reset` a public page, and the shipped gate exempts it
> (`src/Core/PublicAccessGate.php:95`). Left unamended, the two requirements contradicted
> each other, and an implementation following AAG-01 literally would have deleted the
> password-recovery exemption — the regression AAG-06 exists to prevent. The noun was
> widened to the anonymously served page set; AAG-01's actual constraint — the decision
> depends on the requested **page**, never on the request **shape** — is unchanged.

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

#### Scenario: Every non-public controller page is gated for both bypass shapes

- GIVEN a table of non-public page names covering the administrative and plugin surfaces
- WHEN each page is requested anonymously as `POST ?page=<name>&nlogin=1`, and again as `POST ?page=<name>` with `user`/`password`
- THEN every response is a `302` to `index.php?page=login`
- AND no response is a `200` carrying a controller body

> **Superseded by AAG-06.** This scenario originally asserted that
> `POST /index.php?page=password_reset&nlogin=1` is gated. That made the entire
> password-recovery flow unreachable anonymously — the login page links to
> `?page=password_reset` and the gate answered `302` back to login — and
> unreachability was never the real mitigation for VULN-0006. LTI-01…LTI-04
> neutralise that denial of service by isolating the reset throttle bucket,
> validating before any counter write, and requiring a CSRF token. AAG-06 states
> the requirement that replaced this one.

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

A non-canonical page value — one the canonical rule rejects, i.e. it resolves to the empty
string — **MUST** be fail-closed: the request is gated and the redirect still lands on the
real login page. A value the rule *accepts* (such as `login `, which resolves to `login`)
is resolved, not rejected, and is exempt on the same footing as its canonical form.

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

#### Scenario: A non-canonical page value is gated, not exempted

- GIVEN an anonymous request naming `?page=LOGIN`
- WHEN the gate evaluates the request
- THEN it returns a `302` whose `Location` is the real login page
- AND the request is not treated as a login page by the gate

#### Scenario: A value the dispatcher canonicalises is exempt

- GIVEN an anonymous request naming `?page=login%20`
- WHEN the gate and the dispatcher evaluate it
- THEN both resolve the requested page to `login`
- AND the gate returns `null` (not intercepted), because `index.php` trims the value and dispatches the login controller

> *(Corrected during verify.)* These were a single scenario asserting that `?page=LOGIN`
> **and** `?page=login%20` both return `302` and that neither is treated as a login page.
> The second half was false: `login ` trims to `login`, exactly as the resolution table
> above states, and the shipped gate exempts it
> (`tests/Components/PublicAccessGateTest.php:444-470`). Worse, that pair of assertions is
> precisely what a **raw** `$_GET['page']` comparison would produce — `LOGIN` and `login `
> both differ from the string `login` — so the scenario would have ratified the defect
> AAG-02 exists to remove, and it contradicted AAG-02's own table, AAG-05, design D2 and
> `index.php:216-229`. The split keeps the fail-closed half (`LOGIN` is rejected by the
> canonical rule, so the gate agrees with a dispatcher that dispatches nothing) and states
> the mirroring half honestly.

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

- GIVEN an anonymous `POST /index.php/favicon.ico?page=admin_info`
- WHEN the gate evaluates the request
- THEN the response is a `302` to `index.php?page=login` (previously `200`)

*(Re-pointed during PR 4.)* This scenario originally used `page=password_reset` as the private page. AAG-06 makes `password_reset` public, so that combination is a legitimate exemption; the rule under test is unchanged and is now asserted against a genuinely private page.

#### Scenario: A public path carrying a private page is gated (GET)

- GIVEN an anonymous `GET /index.php/favicon.ico?page=admin_info`
- WHEN the gate and the browser-probe short-circuit are evaluated
- THEN the response is a `302` to `index.php?page=login` (previously `204`)

*(Re-pointed during PR 4, same reason as the POST scenario above.)*

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

### Requirement: AAG-06 — `password_reset` is a public page

`password_reset` **MUST** be treated as a public page by the anonymous access gate: both
the render of the form (`GET ?page=password_reset`) and its submission
(`POST ?page=password_reset`) **MUST** be exempt, because the login page links to it and
password recovery is a legitimate pre-authentication flow.

The flow **MUST** be made safe by controls on the page itself, never by unreachability:
an isolated throttle bucket that cannot deny authentication (LTI-01, LTI-02, LTI-05),
validation that precedes any counter write (LTI-03), and a required CSRF token on both
reset entry points (LTI-04).

The exemption **MUST** remain page-based, never shape-based: an extra `nlogin` parameter
on `page=password_reset` does not change the decision (AAG-01).

A public page reached through a framework exact public path (`/favicon.ico`,
`/apple-touch-icon*.png`) is likewise exempt, because AAG-05 resolves the *named page*
and that page is public. The AAG-05 path rule itself is unchanged: a private page carried
on a public path stays gated.

#### Scenario: The password-reset form renders anonymously

- GIVEN stealth mode disabled and no authenticated session
- WHEN an anonymous `GET /index.php?page=password_reset` is evaluated by the gate
- THEN the gate returns `null` (not intercepted)
- AND the request reaches `password_reset` instead of being redirected to login

#### Scenario: The password-reset submission reaches the handler

- GIVEN stealth mode disabled and no authenticated session
- WHEN an anonymous `POST /index.php?page=password_reset` carrying a valid CSRF token is evaluated by the gate
- THEN the gate returns `null` (not intercepted)
- AND the response is not a `302` to `index.php?page=login`

#### Scenario: The exemption is page-based, not shape-based

- GIVEN stealth mode disabled and no authenticated session
- WHEN an anonymous `POST /index.php?page=password_reset&nlogin=1` is evaluated by the gate
- THEN the gate returns `null` (not intercepted), exactly as for the same request without `nlogin`

#### Scenario: Reachability does not restore the denial of service

- GIVEN the password-reset page is now anonymously reachable
- WHEN an anonymous visitor submits repeated empty reset requests
- THEN the authentication lockout is not incremented (LTI-01, LTI-03)
- AND authentication from the same address is not banned (LTI-05)

#### Scenario: Every page outside the public set stays gated

- GIVEN the public-page set is exactly `login` and `password_reset`
- WHEN an anonymous request names any other controller page
- THEN it is still gated with a `302` to `index.php?page=login`

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
