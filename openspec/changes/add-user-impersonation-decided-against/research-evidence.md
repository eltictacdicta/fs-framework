# Research evidence — add-user-impersonation

**Gathered by**: the orchestrator, not the `sdd-research` phase.
**Why**: the phase returned `blocked` — its launch prompt declares `documentation=[]; open-web=[]`
(see `opencode.json:211`), so admission was denied for every evidence class and it correctly
refused to emit any claim. That outcome is recorded, unaltered, in `research.md`. Rather than
waive the evidence requirement, the orchestrator gathered the evidence using the tools available
in its own runtime (Context7 for library documentation, web search for the rest).
**Status**: does not replace `research.md`; it satisfies the evidence condition that the blocked
phase could not. The phase's own artifact remains the record of the denial.

**Method**: primary sources preferred — official vendor documentation, official Symfony docs via
Context7 (`/symfony/symfony-docs/__branch__7.4`), and project documentation. Every claim carries
its source. **Sourced fact and inference are separated explicitly**; anything unsourced is marked
as such rather than asserted.

---

## Q1 — How do established systems attribute impersonation in audit trails?

**Sourced:**

- **ServiceNow** states the problem outright: *"Impersonation activity is indistinguishable from
  regular user activity in a standard audit trail, you can't reliably attribute changes to the
  administrator who made them rather than the user they were impersonating."* Its remedy is a
  **dedicated impersonation audit set**, separate from ordinary session data, with explicit fields
  `Impersonated by` (who initiated) and `Impersonated to` (whose identity was assumed), plus a start
  entry, all actions in order, and a closing entry. A separate property
  (`glide.audit.track_impersonation`) records *both* the impersonated user and the user ID of the
  person who actually performed the actions.
  <https://www.servicenow.com/docs/r/platform-administration/user-administration/impersonation-audits.html>
  <https://www.servicenow.com/docs/r/platform-security/enable-impersonation-tracking-audit-logs.html>
- **Okta** models impersonation as a **five-event lifecycle** — `user.session.impersonation.grant`,
  `.revoke`, `.extend`, `.initiate`, `.end` — explicitly so that *"security teams can verify exactly
  who granted access, when the support session occurred, and when it concluded."* Impersonation
  cannot occur without explicit Super Admin approval, and events are retained separately with a
  SIEM-export recommendation.
  <https://support.okta.com/help/s/article/read-only-impersonation-access-system-logs-audit-events>
- **Ory** requires that *"the user doing the impersonating remains authenticated, which is critical
  for audit trails"*, and recommends passing the impersonating user's ID alongside the effective
  subject (e.g. `X-Original-Subject-ID`) *"for detailed audit logging"*, so logs record actions taken
  *"on behalf of"* the impersonated user.
  <https://www.ory.com/docs/guides/user-impersonation>
- **PropelAuth** keeps a dedicated audit trail for impersonation actions and lets the audit log be
  filtered by what caused the event, including an `Impersonation` cause.
  <https://docs.propelauth.com/overview/user-management/audit-logs>

**What this means for D3.** The confirmed decision — record the **admin** as the actor with the
target in the detail — matches what every source above does in substance: attribution goes to the
impersonator. ServiceNow's naming is the closest model (`Impersonated by` / `Impersonated to`).

**Recommendation (inference, not sourced):** the same sources consistently make impersonation a
**distinguishable event class** with a **start and an end**, not a normal log line. D3 as confirmed
records the two identities but does not make the events findable *as impersonation*. A distinct
`tipo` (with `usuario` still holding the admin) would match the established pattern and costs
little, since `fs_logs` already has a `tipo` column. This is a strengthening of D3, not a
contradiction of it. Note the consequence already identified in `exploration.md`:
`controller/admin_users.php` filters `all_by('login')`, so a new `tipo` needs its own view.

## Q2 — What session invariants do established frameworks enforce?

**Sourced (Symfony, official docs via Context7 + symfony.com):**

- `switch_user` is a **firewall listener** — configured under `security.firewalls.<name>.switch_user`,
  and it relies on a **user provider** (the same provider that reloads the user from the session).
  <https://symfony.com/doc/current/security/impersonating_user.html>
- The switch is triggered by a request parameter (`_switch_user=<username>`) and **exited with the
  same parameter set to `_exit`** (`?_switch_user=_exit`).
- The impersonator's identity is **preserved, not destroyed**: the token in storage becomes a
  `SwitchUserToken` whose original token is retrievable, which is how the original user is recovered.
- `IS_IMPERSONATOR` is the special attribute to detect an active impersonation, and
  `impersonation_exit_path()` builds the exit link in Twig.
- A `security.switch_user` event (`SwitchUserEvent`) fires on switch.
- **Documented warning:** *"User impersonation is not compatible with some authentication mechanisms
  (e.g. REMOTE_USER) where the authentication information is expected to be sent on each request."*

**Sourced (other ecosystems):**

- **Keycloak**: if the admin and the user are in the **same realm**, *"the admin will be logged out and
  automatically logged in as the user being impersonated"*; in different realms the admin **stays
  logged in and is additionally logged in as the user**. Requires the realm's impersonation role.
  <https://wjw465150.gitbooks.io/keycloak-documentation/content/server_admin/topics/users/impersonation.html>
- **Nextcloud** `impersonate`: adds an impersonate action to the user list, aimed at debugging issues
  reported by users. <https://github.com/nextcloud/impersonate>
- **Ory**: *"A secure impersonation flow doesn't create a new login session for the user being
  impersonated or compromise the impersonating user's authenticated identity."*

**What this means for this change (inference):** the two models in the field are "preserve the
impersonator's identity" (Symfony, Ory — the impersonator's authenticated identity survives) and
"replace it" (Keycloak same-realm — the admin is logged out). **D4 chose the preserve model**, which
is the one Symfony and Ory describe as the secure approach. Keycloak is evidence that the other
choice exists, not that it is preferable.

## Q3 — Is read-only impersonation an established pattern?

**Sourced:**

- **Lattice** ships it as the *only* mode: *"Admin Impersonation is a read-only impersonation. Admins
  cannot make any changes, take any actions on behalf of the user, or download a file while
  impersonating a user."* It shows a banner naming the impersonated user with a *"read-only access
  permissions"* callout, returns via **Log out**, caps the session at **1 hour**, allows impersonating
  **only active profiles**, and lists every impersonation on a dedicated Impersonations page.
  <https://lattice.zendesk.com/hc/en-us/articles/360060517914-Admin-Impersonation>
- **Okta** names the feature *Read Only Impersonation Access*.
  <https://support.okta.com/help/s/article/read-only-impersonation-access-system-logs-audit-events>
- **Pigment** documents read-only impersonation as a first-class guarantee and warns that enforcing it
  is an integration problem: *"Postgres has native support for read-only sessions, but not every
  service does — and whenever we integrate a new one, someone needs to remember to implement
  read-only enforcement for it."*
  <https://engineering.pigment.com/2026/04/08/safe-user-impersonation>
- **Ory** best practices list: *"Restrict available actions during impersonation, for example,
  prevent sensitive operations (password changes, account deletion)"*.

**What this means for D2 (inference):** read-only is a mainstream, vendor-documented mode, not an
invention. Pigment's warning is the actionable part: **enforcement must be central and unavoidable,
not a per-action check**, or a new write path will silently escape it. In FSFramework the central
chokepoints are the model write paths (`fs_model::save`/`delete`) and the controller action dispatch;
the spec must name exactly one and show why nothing can bypass it.

## Q4 — Is Symfony's `switch_user` usable here?

**Verdict: no, and the framework's own documentation is the evidence.**

**Sourced:**

- `switch_user` is configured **inside a firewall** and depends on a **user provider**
  (`security.firewalls.<name>.switch_user`, providers power *"user impersonation and Remember Me"*).
  <https://symfony.com/doc/current/security.html>, <https://symfony.com/doc/current/security/impersonating_user.html>
- **Documented incompatibility:** impersonation *"is not compatible with some authentication
  mechanisms (e.g. REMOTE_USER) where the authentication information is expected to be sent on each
  request."* FSFramework's legacy autologin validates `user` + `logkey` + `auth_sig` on each request —
  that is exactly this shape.
- A known pitfall with several firewalls: a super-admin can impersonate but **cannot exit**
  (symfony/symfony#61773).

**Local evidence:** `composer.json` requires only `symfony/security-csrf`; there is no SecurityBundle
and no firewall. The `SwitchUserToken` / `AuthenticatedVoter` classes present in `vendor/` are dead
weight here.

**Conclusion (inference):** adopting `switch_user` would mean introducing a firewall and a user
provider into an application whose identity model is a mutable session plus legacy cookies — a
disproportionate change that Symfony's own docs warn against. Impersonation must be built on the
project's own session primitives.

## Q5 — Is the cookie/session hazard a known failure mode?

**Sourced:** I did **not** find the specific failure mode — "impersonation replaces the
impersonator's *persistent cookies*, so a later session loss restores the wrong user" — documented as
a named bug class. This is an honest negative result.

**Sourced (adjacent, and supportive):**

- **Ory** states the invariant D4 relies on: a secure flow must not *"compromise the impersonating
  user's authenticated identity"*.
- **Symfony** enforces the same invariant structurally: the impersonator's token is retained inside
  the `SwitchUserToken`.
- **Keycloak** deliberately does the opposite in the same realm (logs the admin out), which is
  evidence that the choice is real and explicitly made — not that D4 is wrong.

**Inference, clearly marked:** the hazard identified in `exploration.md` — the per-request re-issue of
`user`/`logkey`/`auth_sig` for the session nick (`base/fs_login.php:527` → `:558`) would overwrite the
admin's cookies with the target's, so a session loss restores the **target** — is **our own analysis,
not a sourced failure mode**. What the sources support is the *direction* of the fix (preserve the
impersonator's identity), which is what D4 chose.

## Deviations and contradictions

- **No source contradicts D1, D2, D3 or D4.** D1 (non-admins only) is actively endorsed: Ory's best
  practices say to *"Limit who can be impersonated (protect executives, other admins)"*. D2 is
  mainstream. D3's direction (attribute to the impersonator) is unanimous across vendors. D4 matches
  the Symfony/Ory model.
- **D6 deviates from one best-practice list.** Ory lists *"Notify impersonated user
  (email/notification)"* under transparency. Okta instead requires **prior admin approval**, and
  Lattice neither notifies nor requires approval — it only logs. So notification is common but not
  universal; D6 (audit-only) is defensible and is recorded as a deviation rather than an error.
- **A gap in the accepted defaults:** Ory (*"Implement time limits and automatic session
  expiration"*), Lattice (1 hour), and PropelAuth (*"impersonation sessions are typically one hour
  long and cannot exceed four hours"*) all bound the session. The accepted default — "expiry inherits
  the normal session policy" — is **hollow as things stand**, because `fs_login::save_session_data()`
  re-stamps `login_time` on every authenticated request (`base/fs_login.php:546`), so an invented
  "expires in N minutes" would never fire. If a bound is wanted it must be implemented explicitly
  against its own timestamp; the spec must not claim a limit it does not enforce.
  <https://www.ory.com/docs/guides/user-impersonation>, <https://lattice.zendesk.com/hc/en-us/articles/360060517914-Admin-Impersonation>,
  <http://propelauth.com/post/secure-user-impersonation>

## Sources

1. ServiceNow — User impersonation auditing — <https://www.servicenow.com/docs/r/platform-administration/user-administration/impersonation-audits.html>
2. ServiceNow — Enable impersonation tracking in audit logs — <https://www.servicenow.com/docs/r/platform-security/enable-impersonation-tracking-audit-logs.html>
3. Okta — Read Only Impersonation Access System Logs Audit Events — <https://support.okta.com/help/s/article/read-only-impersonation-access-system-logs-audit-events>
4. Ory — Implementing user impersonation securely — <https://www.ory.com/docs/guides/user-impersonation>
5. PropelAuth — Secure User Impersonation — <http://propelauth.com/post/secure-user-impersonation>
6. PropelAuth — Audit Logs — <https://docs.propelauth.com/overview/user-management/audit-logs>
7. Lattice — Admin Impersonation — <https://lattice.zendesk.com/hc/en-us/articles/360060517914-Admin-Impersonation>
8. Pigment Engineering — Impersonation Done Right: Tokens, Read-Only Guarantees — <https://engineering.pigment.com/2026/04/08/safe-user-impersonation>
9. Symfony — How to Impersonate a User — <https://symfony.com/doc/current/security/impersonating_user.html> (and via Context7 `/symfony/symfony-docs/__branch__7.4`, `security/impersonating_user.rst`)
10. Symfony — Security — <https://symfony.com/doc/current/security.html>
11. Symfony issue #61773 — Can't exit impersonation with multiple firewalls — <https://github.com/symfony/symfony/issues/61773>
12. Keycloak — Impersonation — <https://wjw465150.gitbooks.io/keycloak-documentation/content/server_admin/topics/users/impersonation.html>
13. Nextcloud impersonate — <https://github.com/nextcloud/impersonate>
14. Casdoor — User impersonation — <https://casdoor.ai/docs/user/impersonation>

## Verdict

The confirmed decisions are **consistent with established practice**, with two evidence-driven
refinements the proposal should absorb:

1. Make impersonation events a **distinguishable class with a start and an end** (Q1) while keeping
   the admin as `usuario` — a strengthening of D3, not a change to it.
2. Either implement a **real session bound** with its own timestamp, or state plainly that this slice
   has none (Q3/deviation). Do not claim a limit the code does not enforce.

And one finding that closes a question for good: **Symfony's `switch_user` is not usable here** — it
requires a firewall and a user provider, and Symfony's own docs warn it is incompatible with
authentication that resends credentials on every request, which is precisely FSFramework's legacy
cookie path.
