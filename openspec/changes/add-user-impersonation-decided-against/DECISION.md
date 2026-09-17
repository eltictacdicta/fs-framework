# Decision — add-user-impersonation: NOT PURSUED

**Date**: 2026-09-17
**Status**: **decided against — the change will not be implemented.**
**Artifacts in this directory are kept as an analysis record, not as an active change.**

## Decision

Impersonation ("enter as this user") will **not** be built in FSFramework. For the rare
case, a throwaway test user is used instead.

## Why

The owner is and will be the **only administrator**. That removes the threat model the
feature is normally justified by: delegating diagnosis to support staff **without handing
them credentials**. With no support staff, that rationale does not apply, and the feature
reduces to a debugging convenience.

What the framework already covers **with no change to the session core**:

- `controller/admin_user.php` shows any user's roles and permissions
  (`get_role_allowed_pages()`).
- `controller/admin_users.php` has the per-page permission grid (`all_pages()`).

So the common diagnosis — "this user cannot see page X" — is already possible. What
impersonation would add is seeing the **effective experience** (rendered menu, plugin
interaction, data-scoped views), which can differ from the configured permissions. That is
a convenience, and it was not worth a change to the authentication core.

## What would reopen this

- A second administrator, or any support staff who need to diagnose without credentials.
- A recurring, documented need to see the rendered experience rather than the configuration.
- A support workflow that today relies on sharing passwords or temporarily editing permissions —
  the practices impersonation is designed to replace.

## What is preserved here, and is valuable independently of the feature

1. **The per-request cookie re-stamp.** On every authenticated request the framework
   re-issues the legacy `user` / `logkey` / `auth_sig` cookies for whatever nick the session
   currently holds (`base/fs_login.php:527` → `:558` → `src/Security/LegacyAuthBridge.php:177-189`).
   Consequence for anything that ever swaps the session identity: the pre-existing user's
   cookies are overwritten, and a later session loss restores the **wrong** person. This is a
   property of the auth core, not of impersonation.
2. **`fs_users.log_key` does not protect sessions.** It guards the cookie autologin only;
   `SessionManager::isValid()` never consults it. Pinned by
   `tests/Base/SessionIdentityCharacterizationTest.php`.
3. **Symfony's `switch_user` is not usable here.** It requires a firewall and a user provider,
   and Symfony's own documentation warns impersonation is incompatible with authentication
   that resends credentials on every request — which is exactly this legacy cookie path.
   Evidence: `research-evidence.md` Q4.
4. **The external evidence** on audit attribution, read-only enforcement and session bounds
   (`research-evidence.md`, 14 cited sources) applies to any future privileged-access feature.

## What was never resolved (only relevant if this is revived)

- The read-only write funnel was settled as `fs_db2::exec()` — `fs_model::save()`/`delete()`
  are abstract, so the model layer is a set of per-model write paths and leaks by design.
- Blocking all writes would also silence the audit sink (`fs_log_manager::save()` →
  `fs_log::save()` → `exec`), so the guard needs a carve-out for it.
- The confirmed decisions D1–D8 are recorded in `exploration.md`. They were collected before
  the concept had been explained to the owner, so **they should be re-confirmed, not trusted**,
  if the change is ever revived.

## Note on process

The eight scope decisions (D1–D8) were gathered before the owner had been told what
impersonation is. They read as informed because the questions were precise; precision is not
comprehension. Any future change of this shape should state what the feature **is** and what
problem it solves, in the owner's language, before the first scoping question.

## Proposals in this directory

- `exploration.md` — in-repo findings, the two seams, ordered risks, decisions D1–D8.
- `research.md` — the `blocked` outcome of the `sdd-research` phase (empty evidence grants).
- `research-evidence.md` — orchestrator-gathered evidence, 14 cited sources.
- `proposal.md` — the proposal as written before the decision.
