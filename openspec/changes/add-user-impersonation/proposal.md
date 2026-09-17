# Proposal: add-user-impersonation

## Intent

An admin told "my menu is missing" has only three bad options: ask for the password, temporarily change permissions, or reset the password. Each breaks attribution or gets forgotten. Read-only impersonation with audit diagnoses using the target's own menu and permissions, changes no credential and no permission, and records who did what. No impersonation mechanism exists today (`exploration.md` §1).

## Scope

### In Scope
- Ability granted by `fs_users.admin === true`; target **non-admin** and enabled; disabled under `FS_DEMO` (D1).
- Read-only "view as": target menu and permissions, writes blocked by one central guard (D2).
- Audit: admin in `fs_logs.usuario`, target in `detalle`, dedicated `tipo` with start and end events (D3, D8).
- Session bound of 1 hour against its own timestamp (D7).
- Preserve the admin's legacy cookies; suppress the per-request re-stamp while impersonating (D4).
- Persistent banner plus one-click leave; no notice to the target (D6).

### Out of Scope
- Full act-as (writes on behalf of the target); impersonating admins or self; a shared-scope notion (none exists); any TTL other than D7's 1 hour.
- Symfony `switch_user`: it needs a firewall and a user provider, and Symfony's docs warn it is incompatible with auth that resends credentials each request — exactly this legacy `user`/`logkey`/`auth_sig` path (`research-evidence.md` Q4).
- The dedicated impersonation-events listing view — D8's consequence, deferred (see Delivery).

## Capabilities

### New Capabilities
- `user-impersonation`: entering, navigating as, and leaving a read-only target session; the write guard; the audited start/end lifecycle.

### Modified Capabilities
- `session-integrity`: `fs_login::save_session_data()`/`save_cookie()` MUST suppress the identity re-stamp and the legacy cookie re-issue while an impersonation is active.

## Approach

Two seams are required (`exploration.md` §1).

- **Seam A — identity install.** A new explicit swap method beside `SessionManager::login()` (`src/Security/SessionManager.php:364`): writes the full identity key set, without `regenerateId()` and without rotating any `log_key`.
- **Seam B — non-negotiable.** `fs_login::save_session_data()` (`base/fs_login.php:527`) and `save_cookie()` (`:558`) must become impersonation-aware. Without it the next request re-issues `user`/`logkey`/`auth_sig` for the session nick (`LegacyAuthBridge::issueLegacyCookies()`, `:177-189`), the impersonated identity becomes the persistent one, and a later session loss restores the **target** (`:275-287`).

Entry is POST + CSRF (`admin_stealth::enforcePostAction()`, `controller/admin_stealth.php:85-102`). Leave must not call `logout()`, which rotates the target's `log_key` (`base/fs_login.php:160-194`).

## How Read-Only Is Enforced

Chokepoint: **`fs_db2::exec()`** (`base/fs_db2.php:251-277`), documented as the funnel for inserts, updates and deletes; reads use the disjoint `select()`/`select_limit()` path (`:415`, `:442`). `fs_model::save()`/`delete()` are **abstract** (`base/fs_model.php:87,99`) and every model writes through its own `$this->db->exec()` (e.g. `model/fs_access.php:110-131`), so choosing them is per-action enforcement — exactly what Pigment warns will leak when a new write path appears.

Constraints for `sdd-design`: the audit sink (`fs_log_manager::save()` → `fs_log::save()` → `exec`, `model/fs_log.php:145`) must stay writable, or events queued during impersonation are lost; direct PDO writes in `plugins/system_updater` and `base/fs_maintenance_mode.php:412` bypass the guard (narrow, outside page flows); the fail-closed response is deferred to design.

## Affected Areas

| Area | Impact |
|------|--------|
| `src/Security/SessionManager.php` | New swap/leave methods + bound timestamp |
| `base/fs_login.php` | Impersonation-aware re-stamp suppression (Seam B) |
| `base/fs_db2.php` | Read-only guard in `exec()` |
| `base/fs_controller.php`, `base/fs_core_log.php` | Audit actor is the admin, not the session nick |
| `controller/admin_user.php`, `themes/AdminLTE/view/admin_user.html.twig` | Entry action |
| `themes/AdminLTE/view/header.html.twig` | Banner + leave |
| `tests/Base/`, `tests/Security/` | New coverage |

## Delivery

The work as scoped likely **exceeds the 400-line `single-pr` budget** (two auth seams, a guard, UI, TDD coverage). **First slice**: Seam A, Seam B, the `exec()` guard, start/end audit, leave, tests. **Deferred**: the dedicated impersonation-events view, because `admin_users` filters `all_by('login')` (`controller/admin_users.php:58-60`) and would not list the new `tipo`.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Impersonated identity becomes persistent; session loss restores the target (CRITICAL, `exploration.md` R1) | High | Seam B is mandatory; leave never rotates `log_key` |
| Audit mis-attribution (CRITICAL, R2) | High | D3/D8; actor set explicitly, never read from the session nick (`base/fs_controller.php:907`) |
| Read-only leaks through a new write path | Med | One `exec()` chokepoint, not per-model checks |
| Identity desync across eight session keys (HIGH, R5) | Med | A single swap method writes the full key set |
| No exclusivity: the target's live session is untouched (HIGH, R4) | Med | Accepted — read-only, audit-only, no notice |
| Single-PR review budget exceeded | High | Slice as defined above; escalate to `size:exception` or chained PRs |

## Rollback

Disable the grant check and remove the entry button; the guard and banner become inert. There is no schema change and no persisted impersonation state. An interrupted impersonation leaves only session keys, cleared on the next login or leave; because D4 keeps the admin's cookies, no session loss restores the target. Reversal is a code revert of the two seams and the guard.

## Dependencies

- The 37 characterization tests already pin the auth core (`tests/Base/SessionIdentityCharacterizationTest.php`, `FsLoginCharacterizationTest.php`, `AuthorizationFreshnessTest.php`). This change builds on that net and must not duplicate its coverage.

## Success Criteria

- [ ] An admin enters as a non-admin and sees that user's menu and permissions.
- [ ] No write reaches the database during an impersonation.
- [ ] The admin's own session and cookies survive the whole cycle.
- [ ] Leaving restores the admin without rotating any `log_key`.
- [ ] Start and end events are recorded in `fs_logs` with the admin as `usuario` and the target in `detalle`.
- [ ] The impersonation session ends by itself after the configured bound.
- [ ] The impersonated user receives no notice.
