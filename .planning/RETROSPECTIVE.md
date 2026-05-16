# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v0.11.0 — Deferred Items Cleanup

**Shipped:** 2026-05-16
**Phases:** 4 | **Plans:** 5 | **Tasks:** 16

### What Was Built
- 5 pre-existing test failures fixed (full suite: 0 failures, 0 errors)
- empresa.php email delegated to MailService (removed direct PHPMailer dependency)
- Plugin management extracted to PluginInstaller + PluginActionHandler (admin_home 1053→698 lines)
- fs_mysql decomposed into TypeNormalizer + SchemaInspector + SchemaComparator

### What Worked
- Phase 4 was the highest-leverage win — fixing tests first unblocked everything else
- MailService delegation was the quickest phase (1 plan, 5 tasks, ~8 min) — already had the service, just needed to wire it
- The delegation-to-existing-service pattern (Phase 5) proved cleaner than full extraction (Phase 7)
- Risk-ascending build order was correct: tests → low-risk → medium → high

### What Was Inefficient
- SchemaComparator extraction hit complexity limits with `compare_constraints` — constraint signature normalization too complex for clean extraction; kept in fs_mysql
- Plan file naming conventions (canonical vs noncanonical) caused wasted cycles in Phase 4 execution
- Inline execution without subagents works but is token-heavy — agent installation would speed future phases

### Patterns Established
- Extract pure functions first (TypeNormalizer), then read-only queries (SchemaInspector), then mutations (SchemaComparator)
- Service classes receive dependencies via constructor (DI pattern)
- Controller-side error messages stay in controllers — service classes return data, not messages

### Key Lessons
1. Fix tests before anything else — a clean test baseline is the best foundation
2. Existing service classes (MailService) are golden — delegation beats rewriting
3. Not everything needs to be extracted — some complexity (compare_constraints) is fine where it is
4. Plan file naming matters (`XX-YY-PLAN.md`) — the SDK is strict about canonical formats

### Cost Observations
- Model mix: No subagent spawning (all inline) — agents would reduce cost significantly
- Sessions: 1 continuous session spanning ~4 hours
- Notable: Plan + execute cycle became progressively faster with repetition

---

## Milestone: v0.10.8 — Tech Debt Cleanup

**Shipped:** 2026-05-16
**Phases:** 3 | **Plans:** 9 | **Tasks:** 22

### What Was Built
- PHP 5.6 version guards updated to 8.2
- PHPMailer 5.x + compat bridge deleted (61 files)
- @ error suppression replaced with proper guards (11 files, ~35 ops)
- strict_types adopted in 15 base files
- SHA1/MD5 checking delegated to legacy_support plugin
- StealthMode decomposed into CssSanitizer + HtmlSanitizer

### What Worked
- Bulk cleanup (61 files deleted via PHPMailer removal) created momentum
- Deferred items list provided clear scope for v0.11.0 — no ambiguity about what to tackle next
- Each phase committed independently with test verification

### Patterns Established
- StealthMode facade pattern: public API preserved, internals decomposed
- PostgresSQL type conversion centralized in FsMysqlSchemaUtility

### Key Lessons
1. Big deletions (PHPMailer 5.x) are satisfying but need careful dependency checking
2. Deferred items are a powerful mechanism — they turn "we should do this" into concrete backlog

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v0.10.8 | 1 | 3 | Initial structure established, deferred items list created |
| v0.11.0 | 1 | 4 | Risk-ascending ordering adopted, pattern mapper skipped, inline execution |

### Cumulative Quality

| Milestone | Tests | Errors | Files Changed | New Classes |
|-----------|-------|--------|--------------|-------------|
| v0.10.8 | 124→381 | 2→0 | 107 | 2 (StealthMode-related) |
| v0.11.0 | 381→383 | 0→0 | 31 | 5 (Core + Database) |

### Top Lessons (Verified Across Milestones)

1. Test-first and incremental verification are essential — run the suite after every change
2. Deferred items list from one milestone feeds directly into next milestone planning
3. Extraction patterns (pure → read-only → mutations) work consistently
