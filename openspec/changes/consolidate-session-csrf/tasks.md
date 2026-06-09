# Tasks: Consolidate Session & CSRF Handling

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~180 (source: 57, tests: 120) |
| 400-line budget risk | **Low** |
| Chained PRs recommended | **No** |
| Suggested split | Single PR |
| Delivery strategy | ask-always |
| Chain strategy | N/A |

Decision needed before apply: **No**
Chained PRs recommended: **No**
Chain strategy: pending
400-line budget risk: **Low**

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | SessionManager: public resolveSessionName, initialize gate, PHPSESSID migration + tests | PR 1 | Foundation; StealthMode + CsrfManager depend on this |
| 2 | StealthMode session_name + CsrfManager token guard + tests | PR 1 | Core fix; depends on unit 1 |
| 3 | Integration tests + E2E verification | PR 1 | Verifies full flow; all in one PR |

---

## Phase 1: SessionManager Foundation (TDD)

- [x] 1.1 Make `resolveSessionName()` public (`src/Security/SessionManager.php:133`: `private` → `public static`)
- [x] 1.2 Add `migrateLegacyPhpSession()` private method — detect `PHPSESSID` cookie, copy `$_SESSION`, expire old cookie, set `_migrated_from_phpsessid` flag (satisfies SC-04, SC-05)
- [x] 1.3 Add name-match gate in `initialize()`: when `session_status() === PHP_SESSION_ACTIVE`, verify `session_name() === self::resolveSessionName()` before wrapping with `PhpBridgeSessionStorage` (satisfies SC-03)
- [x] 1.4 Add PHPSESSID cookie detection block in `initialize()` before session-start paths — call `migrateLegacyPhpSession()` when cookie present and session not active
- [x] 1.5 [TEST] Write `testInitializeSkipsReinitOnMatchingNamedSession` in `tests/Security/SessionManagerTest.php` — set up active `FSSESS_xxx` session, call `getInstance()`, assert no double `session_start()` (verifies SC-03)
- [x] 1.6 [TEST] Write `testMigrateLegacyPhpSessionTransfersData` in `tests/Security/SessionManagerTest.php` — simulate `PHPSESSID` cookie with `$_SESSION` data, assert contents transferred to `FSSESS_xxx`, assert `PHPSESSID` cookie expired (verifies SC-04)

## Phase 2: StealthMode + CsrfManager (TDD)

- [x] 2.1 [TEST] Write `testEnsurePhpSessionStartedSetsSessionName` in `tests/Components/StealthModeTest.php` — invoke `ensurePhpSessionStarted()` via reflection, assert `session_name()` equals `SessionManager::resolveSessionName()` (verifies SC-01, SC-02)
- [x] 2.2 [TEST] Write `testTokenPresenceGuardRefreshesWhenAbsent` in `tests/Security/CsrfManagerTest.php` — set `$_SESSION` without `_csrf/fs_form`, call `getManager()`, assert token generated (verifies SI-01 PhpBridge path)
- [x] 2.3 [TEST] Write `testTokenPresenceGuardCachesPerRequest` in `tests/Security/CsrfManagerTest.php` — call `getManager()` twice, verify second call skips re-check (verifies SI-01 cache contract)
- [x] 2.4 Add `session_name(SessionManager::resolveSessionName())` in `src/Core/StealthMode.php:470-475` inside `ensurePhpSessionStarted()`, before `session_start()`; add `use FSFramework\Security\SessionManager` if needed (satisfies SC-02)
- [x] 2.5 Add `private static bool $tokenVerified = false` property in `src/Security/CsrfManager.php`; add token-presence guard at end of `ensureSession()` — if `!self::$tokenVerified`, check `_csrf/fs_form` in bag, call `refreshToken('fs_form')` if absent, set `self::$tokenVerified = true` (satisfies SI-01)
- [x] 2.6 Update `resetCsrfState()` in `tests/Security/CsrfManagerTest.php` to reset `tokenVerified` via Reflection

## Phase 3: Integration & E2E Verification

- [x] 3.1 Integration test: StealthMode + SessionManager full bootstrap — simulate stealth request, assert session opens under `FSSESS_xxx`, assert no `PHPSESSID` cookie in response (verifies SC-01, SC-02, SC-05)
- [x] 3.2 Integration test: CSRF token survives `migrate(true)` — simulate login → session regenerate → new page load, assert form token valid in both `NativeSessionStorage` and `PhpBridgeSessionStorage` paths (verifies SI-01, LC-02)
- [x] 3.3 E2E — Playwright: stealth admin panel access `?page=login&adminpanel=HASH` from normal browser, assert no "Token de seguridad inválido" error (verifies LC-02, SC-02)
- [x] 3.4 Full regression: `ddev exec php vendor/bin/phpunit` — 494 tests pass (baseline was 436; 58 new tests from all 3 phases)

## Dependency Graph

```
Phase 1                       Phase 2                       Phase 3
──────────────────────────────────────────────────────────────────────
1.1 (visibility)
  │
  ├─► 1.2 (migration method)
  │     └─► 1.4 (cookie detect)
  │
  ├─► 1.3 (name gate)
  │
  ├─► 1.5 [TEST: gate] ──► depends on 1.1, 1.3
  └─► 1.6 [TEST: migrate] ──► depends on 1.1, 1.2, 1.4

                              2.1 [TEST: stealth] ──► depends on 1.1
                              2.2 [TEST: csrf guard] ──► depends on 1.1
                              2.3 [TEST: csrf cache] ──► depends on 1.1, 2.2
                              2.4 (stealth impl) ──► depends on 1.1
                              2.5 (csrf impl) ──► depends on 1.1
                              2.6 (test reset) ──► depends on 2.5

                                                        3.1 (integration) ──► depends on all
                                                        3.2 (integration) ──► depends on all
                                                        3.3 (E2E) ──► depends on all
                                                        3.4 (regression) ──► depends on all
```

## File Change Summary

| File | Action | Est. Lines | Tasks |
|------|--------|-----------|-------|
| `src/Security/SessionManager.php:133` | Modify visibility | 1 | 1.1 |
| `src/Security/SessionManager.php:82-93` | Modify initialize gate | 12 | 1.3, 1.4 |
| `src/Security/SessionManager.php` (new) | Add migrateLegacyPhpSession | 25 | 1.2 |
| `src/Core/StealthMode.php:470-475` | Modify ensurePhpSessionStarted | 7 | 2.4 |
| `src/Security/CsrfManager.php:129-145` | Modify ensureSession | 14 | 2.5 |
| `tests/Security/SessionManagerTest.php` | Add 2 tests | 45 | 1.5, 1.6 |
| `tests/Components/StealthModeTest.php` | Add 1 test | 25 | 2.1 |
| `tests/Security/CsrfManagerTest.php` | Add 2 tests + reset fix | 45 | 2.2, 2.3, 2.6 |
| Integration tests | Add 2 tests | 30 | 3.1, 3.2 |
| E2E scripts | Verify / add scenario | 5 | 3.3 |

## Verification Matrix

| Spec Requirement | Tests | Tasks |
|------------------|-------|-------|
| SC-01: All session_start() sites use resolveSessionName | 1.5, 2.1, 3.4 | 1.3, 2.4 |
| SC-02: StealthMode sets session_name | 2.1, 3.1, 3.3 | 2.4 |
| SC-03: SessionManager skips re-init on named session | 1.5 | 1.3 |
| SC-04: Legacy PHPSESSID migration | 1.6 | 1.2, 1.4 |
| SC-05: Only FSSESS_xxx cookie in response | 1.6, 3.1 | 1.2, 1.4 |
| SI-01: CSRF token survives migrate(true) | 2.2, 2.3, 3.2 | 2.5, 2.6 |
| LC-02: Login CSRF under unified session | 3.2, 3.3 | All |
