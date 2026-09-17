# Exploration — add-user-impersonation

**Status**: exploration complete. The change was **decided against** after the proposal
was written — see `DECISION.md`. Nothing below is an active recommendation; the decisions
and findings are kept as an analysis record.
**Artifact store**: openspec. **Scope**: core (`base/`, `src/`, `controller/`, `model/`, `themes/`).
**Strict TDD**: active (`openspec/config.yaml`). Tests: `ddev exec php vendor/bin/phpunit`.

## Executive summary

Feasible, but **not as a thin "session swap"**. The load-bearing discovery is that
FSFramework re-derives session identity on **every authenticated request**:
`fs_controller::log_in()` (`base/fs_controller.php:903-912`, called from the
constructor at `:241`) → `fs_login::log_in()` (`base/fs_login.php:116-154`) →
`log_in_cookie()` (`:224-262`) → `applyTrustedSessionLogin()` (`:281-291`) →
`save_session_data()` (`:527-552`) → `save_cookie()` (`:558-596`).

That last step re-issues the legacy `user` / `logkey` / `auth_sig` cookies **for
whatever nick the session currently holds** (`src/Security/LegacyAuthBridge.php:177-189`).
So installing an impersonated identity does not merely change the session: on the
next request it overwrites the admin's cookies with the target's, and because
`LegacyAuthBridge::isLegacyUserEligibleForCookieRestore()` requires
`session_nick === cookie_user` when a session nick exists (`:275-287`), a later
session loss silently restores the **target**, not the admin.

A safe design must therefore own two seams, not one: the identity install **and**
the per-request cookie re-stamp.

There is **no impersonation mechanism anywhere** today. Grep for
`impersonat|suplantar|switch_user|act_as|entrar como` finds zero matches in
`controller/`, `base/`, `src/`, `model/`, `themes/` and `plugins/`; the only hits
are unused Symfony classes in `vendor/symfony/security-core` and the
characterization tests, which mention impersonation only as motivation.

The Laravel/Filament "two guards" model does **not** map here: there is exactly one
mutable session identity and no wired Symfony firewall (`composer.json` requires
only `symfony/security-csrf`), so `SwitchUserToken` is present but unusable as-is.

## Findings

### 1. Identity write/read path and the seams

- **Modern write primitive**: `SessionManager::login()` (`src/Security/SessionManager.php:364-381`)
  writes `user_nick`, `user_email`, `user_role`, `user_admin`, `login_time`,
  `last_activity`, `user_logkey`, `user_logged_in` in one pass and calls
  `regenerateId()`. It does **not** touch legacy cookies.
- **Per-request re-stamp (the critical one)**: `fs_login::save_session_data()`
  (`base/fs_login.php:527-552`) re-stamps all identity keys (`login_time` /
  `last_activity` reset to now) and calls `save_cookie()` (`:558-596`).
- **Read path**: `fs_auth::user()` (`base/fs_auth.php:71-90`) re-fetches from the DB
  through `LegacyUserService::findByNick()`, cached in the static `self::$currentUser`.
  `SessionManager::getCurrentUserNick()/getCurrentRole()/isAdmin()`
  (`src/Security/SessionManager.php:477-490`) answer from the session snapshot.
  `isValid()` (`:492-502`) checks only `user_nick` + `SessionPolicy::isExpired()`.

**Seams identified**: (a) a new explicit identity-swap method beside
`SessionManager::login()` that writes the full key set without calling
`save_cookie()` and without rotating any `log_key`; (b) a companion change to
`fs_login::save_session_data()` / `save_cookie()` / `applyTrustedSessionLogin()`
so the re-stamp becomes impersonation-aware; (c) `fs_controller::log_in()`
(`base/fs_controller.php:903`), where `$this->user`, `$this->menu` and the audit
actor all converge.

### 2. UI entry point

- `controller/admin_users.php`, template `themes/AdminLTE/view/admin_users.html.twig`
  (per-user rows at `:128-143`).
- `controller/admin_user.php`, template `themes/AdminLTE/view/admin_user.html.twig`
  (action buttons at `:87-110`) — the most natural home for a single button.
- Existing precedent: `admin_user.php:91-93` temporarily assigns
  `$this->user = $this->suser` **only when editing self**, i.e. a data-level
  "view as" that does not change session identity.
- State-changing conventions to follow: POST-only + CSRF via
  `admin_stealth::enforcePostAction()` (`controller/admin_stealth.php:85-102`,
  the cleanest recent pattern) or `fs_controller::requireCsrf()`
  (`base/fs_controller.php:449-465`); `SafeRedirect` for redirects; duplicate
  petition guards exist (`fs_app::duplicated_petition()` `base/fs_app.php:77-87`).
  `admin_users` is legacy Twig with no HTMX wiring, so HTMX is optional here.

### 3. The way back

`themes/AdminLTE/view/header.html.twig` renders the current user in the navbar
dropdown (`:191-218`) and the sidebar panel (`:223-233`). A **global banner pattern
already exists**: `:261-278` renders `fsc.get_errors()/get_messages()/get_advices()`
as alert blocks at the top of `.content-wrapper`. The "impersonating X — leave"
affordance belongs there. It must **not** reuse `logout`, which rotates the target's key.

### 4. Audit sink

`fs_core_log` is an in-memory queue (`new_error` `base/fs_core_log.php:409`,
`save()` `:440-445`); persistence to `fs_logs` happens only through
`fs_log_manager::save()` (`base/fs_log_manager.php:41-60`), which writes
`usuario = $this->core_log->user_nick()` and runs at the end of `index.php:333-334`.
`set_user_nick()` (`base/fs_core_log.php:451-454`) is called from
`fs_controller::log_in()` (`base/fs_controller.php:907`) and `fs_login::log_out()`
(`base/fs_login.php:192`).

**Consequence**: `fs_logs.usuario` follows the session nick, so under impersonation
every logged action is filed under the **target**. `fs_logs` has a single `usuario`
column; extra context has to go in `detalle`/`controlador` or a new `tipo`.
`controller/admin_users.php:58-60` filters `all_by('login')`, so a new
`impersonation` type would **not** appear in that history without a change.

### 5. Permission coupling

Page access is DB-fresh: `base/fs_controller.php:253` gates on
`$this->user->have_access_to($this->page->name)`, else `access_denied` at `:271`,
driven by the per-request user from `base/fs_login.php:242`. But
`fs_user::get_menu()` (`model/core/fs_user.php:332-351`) consults the **cached**
public `$menu` before the `$this->admin` branch, so freshness depends on a fresh
`fs_user` per request.

Other readers of the acting user that would follow the swap: `allow_delete_on()`
(`:408-422`), `get_role_allowed_pages()` (`:359-383`), `check_for_updates()`
(`base/fs_controller.php:284-302`), `get_last_changes()` cached under
`last_changes_<nick>` (`:509-516`), and the `codagente` link
(`model/core/fs_user.php:286-324`) which plugins consume through
`UserAdapter::getCodagente()`. `FSEventDispatcher` has no production call sites,
so there is no live event coupling to update.

## Risks (ordered)

1. **CRITICAL — the impersonated identity becomes the persistent one.** The
   per-request re-stamp (`base/fs_login.php:281` → `:527` → `:558` →
   `src/Security/LegacyAuthBridge.php:177-189`) overwrites the admin's cookies with
   the target's; a later session loss restores the target.
2. **CRITICAL — audit mis-attribution.** `base/fs_controller.php:907`
   `set_user_nick($this->user->nick)` + `base/fs_log_manager.php:52`.
3. **HIGH — leaving via logout rotates the target's `log_key`.**
   `src/Security/LegacyAuthBridge.php:151-175` + `:240-253`; `base/fs_login.php:160-194`.
4. **HIGH — no exclusivity**: the target's live session is untouched and there is no
   way to know they are online (`src/Security/SessionManager.php:492-502`).
5. **HIGH — identity desync**: eight session keys, written together only by two
   functions; the snapshot readers diverge from the DB reader.
6. **MEDIUM-HIGH — stale reads inside the swap request**: `fs_auth::$currentUser`,
   cached `$menu`, `last_changes_<nick>`, `m_fs_user_all`.
7. **MEDIUM-HIGH — authorization mismatch if the swap happens after the constructor
   gate** (`base/fs_controller.php:241-273`, `load_menu()` at `:908`).
8. **MEDIUM — `StealthMode::isUserLoggedIn()` reads raw `$_SESSION` and returns true
   for any authenticated user** (`src/Core/StealthMode.php:367-385`).
9. **MEDIUM — the maintenance bypass is lost while impersonating a non-admin**
   (`base/fs_maintenance_mode.php:272-314`), which by design cannot query the DB.
10. **MEDIUM — demo and multi-DB policies**: `FS_DEMO` grant-alls
    (`model/core/fs_user.php:338,410`); the multi-DB switch now throws
    (`controller/login.php:119-140`).
11. **LOW-MEDIUM — the absolute session timeout is refreshed every request**
    (`base/fs_login.php:546`), so a naive "expires in N minutes" promise is hollow.
12. **LOW — audit events only flush at the end of `index.php`**, so an `exit()`-based
    controller redirect can drop queued events.

## Open product decisions (proposal is gated on these)

**Blocking:**

- **D3 — Audit contract.** Which nick goes in `fs_logs.usuario` during impersonation:
  the target (today's behaviour) or the admin, and how is the other party recorded
  (`detalle` text, a new `tipo`, or new columns)? One column, incompatible histories.
- **D4 — Cookie behaviour.** (a) keep the admin's legacy cookies and suppress the
  per-request re-stamp while impersonating; (b) accept the target's cookies
  (the section 1 defect); (c) require re-login on leave.

**Scope-shaping:**

- **D1 — Who can be impersonated**: any enabled user (incl. admins) / non-admins only
  / only a shared scope (which does not exist today).
- **D2 — Read-only "view as" vs full "act as"**: view-only removes most of the audit
  risk and is a realistic first slice; full act-as authors documents under the target.
- **D6 — Notice to the impersonated user**: none / audit-only / in-app notice.

**Defaults proposed for the rest, overridable:** expiry inherits the normal session
policy (no dedicated TTL in this slice); the ability is granted by `fs_users.admin === true`;
disabled in `FS_DEMO`; a persistent banner plus one-click leave as the way back.

## Coverage already in place (do not duplicate in the spec)

**37** `#[Test]` methods pin the auth core:

- `tests/Base/SessionIdentityCharacterizationTest.php` (11) — `isValid` semantics,
  the `log_key`-is-not-session-validation property, snapshot `isAdmin`/`getCurrentRole`,
  `login()`'s key duplication, `touch`, `logout`.
- `tests/Base/FsLoginCharacterizationTest.php` (5) — disabled/unknown user rejection,
  `completeSuccessfulLogin()` rotating `log_key` and regenerating the session, cookie
  `hash_equals` success/failure.
- `tests/Base/AuthorizationFreshnessTest.php` (21) — `have_access_to`/`get_menu`
  freshness, `fs_auth::isAdmin`/`role` ignoring the snapshot,
  `fs_maintenance_mode::hasAdminSession` snapshot rules, missing `fs_user::load_from_session()`.

## Decisions confirmed after exploration

Answers collected from the product owner before the proposal. These are now fixed
inputs for `sdd-propose` and `sdd-spec`.

- **D1 — Who can be impersonated**: **non-admin users only**. Avoids two admin
  accounts acting as each other and keeps privileged attribution unambiguous.
- **D2 — Scope inside the session**: **read-only "view as"**. The admin navigates
  with the target's menu and permissions but writes are blocked, so nothing is
  authored or logged under the target. This removes most of the audit risk and is
  the first slice; full act-as is explicitly out of scope.
- **D3 — Audit contract**: **the actor recorded in `fs_logs.usuario` is the admin**,
  with the target identified in the detail. `fs_logs` has a single `usuario`
  column, so the target has to travel in `detalle` (or a new `tipo`); the spec must
  state exactly how, and note that `admin_users` filters `all_by('login')`.
- **D4 — Cookies**: **preserve the admin's legacy cookies and suppress the
  per-request re-stamp for the duration of the impersonation.** The owner deferred
  this one to technical judgement; it is recorded here as a decision, not an
  implicit default. Rationale: it is the only option that closes the CRITICAL
  re-stamp defect without degrading the experience. Accepting the target's cookies
  *is* the defect, and forcing re-login on leave adds friction without adding
  security. Reversible if the owner disagrees.
- **D6 — Notice to the impersonated user**: **none; audit only**. No in-app notice
  in this slice.
- **Research**: selected and mandatory before the proposal (`sdd-research`).

- **D7 — Session bound**: **1 hour**, implemented against its **own timestamp**, not derived
  from `login_time` (which `fs_login::save_session_data()` re-stamps on every request, making a
  naive limit hollow). Added by the research evidence: Ory recommends time limits, Lattice uses
  1h, PropelAuth cites 1h typical / 4h maximum.
- **D8 — Audit event class**: impersonation gets a **dedicated `tipo` with a start and an end
  event**, with the admin in `usuario` and the target in the detail. This is the Okta
  five-event lifecycle / ServiceNow dedicated-set pattern. Consequence: `admin_users` filters
  `all_by('login')`, so the events need their own view or they will not be listed.
- **Research**: selected; the phase returned `blocked` (empty evidence grants in its launch
  prompt), and the owner chose to have the orchestrator gather the evidence instead. See
  `research-evidence.md`; `research.md` remains the record of the denial.

**Defaults accepted for the remaining decisions** (overridable): the ability is granted by
`fs_users.admin === true`; impersonation is disabled under `FS_DEMO`; the way back is a persistent
banner plus one-click leave.

## Next

**None — the change was decided against.** See `DECISION.md` in this directory.

Nothing here recommends a phase any more: `sdd-propose` already ran and the proposal was
never taken forward. The decisions above are kept as an analysis record. If the change is
ever revived they must be **re-confirmed rather than trusted**, because they were collected
before the concept had been explained to the owner.
