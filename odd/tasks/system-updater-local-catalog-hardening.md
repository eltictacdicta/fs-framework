# Feature: system_updater local-catalog + bounded remote checks

## Objective
Make the `system_updater` update check usable when the server has no outbound
access to GitHub, and stop a blocked-egress host from taking the whole site down
with 504s.

## Problem
Production (`otp.grupoinfrico.com`, FSFramework 0.9.14, Apache + mod_security)
returns site-wide 504 (`AH01075 ... polling`) when the `admin_updater` page's
`action=check_updates` AJAX runs. Root cause is NOT the core version and NOT the
`.htaccess` (separate bug, already fixed): the host cannot reach GitHub
(`Failed to connect to raw.githubusercontent.com port 443: Connection timed out`),
and the update check chains many sequential blocking outbound calls, each burning
its own timeout, saturating PHP-FPM workers.

Worst-case chain (all hang when egress is blocked):
- `admin_updater::fetchRemoteCoreVersion()` — 6 URLs x 15s = 90s
- `updater_manager::check_for_updates()` — 8 URLs x 20s = 160s
- `plugin_downloader::downloads()` — 2 URLs x 10s = 20s
- core `fs_plugin_manager::downloads()` — 10s

## Why
User decision: the local plugin catalog is the single source of truth; it is
maintained by hand (`data/custom_plugins.json`), so the remote plugins JSON must
not be consulted during the update check. Core/updater version checks stay, but
must be time-bounded and fail fast.

## Scope
In:
- Plugin `lib/plugin_downloader.php`: `downloads()` reads ONLY the local catalog.
- Plugin `lib/public_catalog_lookup.php`: local-only (drop remote fallback).
- Plugin `controller/admin_updater.php`: bound `fetchRemoteCoreVersion()` and
  `checkUpdates()`.
- Plugin `lib/updater_manager.php`: bound `check_for_updates()` /
  `fetchRemoteContents()`.
- Plugin tests that encode the old remote-first / merge behavior.
- Core `base/fs_functions.php`: `fs_file_get_contents()` connect timeout +
  bounded non-cURL fallback.

Out:
- Core `fs_plugin_manager::downloads()` (core plugin store) — left as-is; only
  bounded indirectly by the core `fs_file_get_contents()` change.
- `plugin_catalog_manager` manual sync/publish tools — left as-is.

## Constraints
- Generated code/comments in English.
- Keep the existing public method signatures where possible.
- Do not remove the `admin_plugin_store` manual save/sync/publish actions.
- No secrets; no unrelated refactors.

## Tasks
- [ ] T1 — Core: `fs_file_get_contents()` adds `CURLOPT_CONNECTTIMEOUT` and a
  real stream timeout in the non-cURL fallback.
- [ ] T2 — Plugin `plugin_downloader::downloads()`: local catalog only; no remote
  fetch; empty + advisory error when the local catalog is absent/empty.
- [ ] T3 — Plugin `public_catalog_lookup`: local-only check; remove the now-unused
  remote URL helper/loop.
- [ ] T4 — Plugin `admin_updater::fetchRemoteCoreVersion()`: per-call timeout
  15s -> 4s + a global deadline (~6s) over the 6 candidate URLs.
- [ ] T5 — Plugin `updater_manager::check_for_updates()`: `fetchRemoteContents`
  timeout 20s -> 4s + a global deadline (~6s) over the 8 candidate URLs.
- [ ] T6 — Plugin `admin_updater::checkUpdates()`: overall budget (~10s) that
  skips remaining remote steps once exceeded.
- [ ] T7 — Tests: rewrite `PluginDownloaderTest` remote tests to the new
  local-only semantics; keep the suite green.

## Acceptance criteria
- `plugin_downloader::downloads()` never opens a network connection and returns
  the local catalog entries.
- With egress blocked, `checkUpdates()` worst case stays well under 60s (no 504).
- All `plugins/system_updater` tests pass.

## Checks
- `ddev exec php vendor/bin/phpunit --configuration plugins/system_updater/phpunit.xml`
- `ddev exec php -l <each changed file>`

## Route
Delegated writer (>=2 non-trivial files, two repos). Parent verifies tests.

## Progress
- [x] T1..T7 implemented and committed.
- Plugin commits (nested repo plugins/system_updater, master):
  - 2a22a7f fix(htaccess)
  - d9c7a33 chore(release): 2.11.4
  - c02eb97 perf(updater): local catalog + bounded remote checks
- Core commit (fs-framework, master): 19a3952b fix(fs-functions): CURLOPT_CONNECTTIMEOUT.
  NOTE: master already had a bounded non-cURL fallback; only CONNECTTIMEOUT was needed.
- Tests: PluginDownloaderTest 10/20, PluginDownloaderUpdatesTest 17/45,
  PublicCatalogLookupTest 3/4 -> all pass. Full plugin suite aborts on a
  PRE-EXISTING incompatibility (plugin 2.11.x needs core `src/Core/Plugin/*`,
  absent in this checkout); unrelated to this change.
- Native RDD review: BLOCKED. Preflight+START ran (lineage review-20ed662175f00d89,
  medium, lens review-reliability), but the OpenCode review transport rejected the
  reviewer Task with `opencode_review_transport_binding_invalid` because the Task
  runs at the session root (core repo) while the candidate lives in the nested
  plugin repo. No review record; no approval fabricated.

## Follow-up (post-deploy feedback)
- Prod kept returning 504 after applying the plugin fix: `hydrateDownloadList()`
  still fetched remote `fsframework.ini` + `releases.json` for every catalog
  entry (~12 x 5s when GitHub is unreachable) — the earlier hardening missed it.
- Fixed in plugin commit e14b0f1 (hydration now fully local; public check_updates
  path makes no network calls). Released as 2.11.5 (commit 0f84352).
- Tests: PluginDownloaderTest 10/20, PluginDownloaderUpdatesTest 17/45,
  PublicCatalogLookupTest 3/4 -> green.
- Remaining remote calls in check_updates are the updater self-check and the core
  version check, both bounded to ~6s. Core-side fix still pending on prod
  (hotfix/0.9.15, tag 0.9.15).

