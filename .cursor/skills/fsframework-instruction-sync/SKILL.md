---
name: fsframework-instruction-sync
description: >-
  Synchronize AI instruction files across IDEs when shared rules change. Maintains
  parity between AGENTS.md, .github/copilot-instructions.md, and .cursor/rules/*.mdc
  following the canonical hierarchy. Use when updating AGENTS.md, modifying cursor
  rules, changing copilot instructions, or when the user asks to sync AI guidelines.
---

# FSFramework Instruction Sync

## Document Hierarchy

```
AGENTS.md                              ← CANONICAL SOURCE (update first)
  ├── .github/copilot-instructions.md  ← Concise summary for Copilot
  ├── .cursor/rules/fs-framework-general.mdc  ← Always-on Cursor baseline
  └── .cursor/rules/fs-framework-*.mdc        ← Specialized topic rules
```

## Decision: Shared vs Specialized?

Before updating, classify the change:

**Shared change** (affects baseline expectations):
→ Update `AGENTS.md` first, then propagate to all derived docs.

Examples: adding a new mandatory tool, changing PHP version, new security rule,
modifying test expectations, changing architecture conventions.

**Specialized change** (only affects one topic):
→ Update only the owning `.mdc` rule.

Examples: adding a DDEV command example, new Twig macro pattern, PHPUnit helper
for a specific edge case.

## Sync Workflow for Shared Changes

```
Shared Change Sync:
- [ ] 1. Update AGENTS.md (canonical source)
- [ ] 2. Update .github/copilot-instructions.md (derived summary)
- [ ] 3. Update .cursor/rules/fs-framework-general.mdc (Cursor baseline)
- [ ] 4. Update the specialized .mdc rule that owns the topic
- [ ] 5. Verify no contradictions between files
```

## Topic Ownership Matrix

| Topic | Baseline owner | Detail owner |
|-------|---------------|-------------|
| Environment / DDEV | AGENTS.md | fs-framework-ddev.mdc |
| Stack / Architecture | AGENTS.md | fs-framework-general.mdc |
| Security | AGENTS.md | fs-framework-security.mdc |
| Testing | AGENTS.md | fs-framework-testing.mdc |
| Twig / Views | AGENTS.md | fs-framework-twig.mdc |
| Symfony 7.4 | AGENTS.md | fs-framework-symfony74.mdc |
| PHP 8.3 | AGENTS.md | fs-framework-php83.mdc |
| Plugins | AGENTS.md | fs-framework-plugins.mdc |
| Sync rules | AGENTS.md | fs-framework-ai-instructions.mdc |

## Shared Rules That Must Not Drift

These are always synchronized across all docs:

- Mandatory `ddev` for PHP, Composer, PHPUnit
- `ddev start` assumption for service-dependent tasks
- Stack: PHP 8.2+ (prefer 8.3), Symfony 7.4, Twig 3, PHPUnit 11
- Legacy (`base/`, `controller/`, `model/`) vs modern (`src/`) split
- Security baseline: safe SQL, escaped output, CSRF, secure passwords
- Test expectation for new/modified business logic
- Plugin architecture compatibility

## Verification

After syncing, check that:

1. `AGENTS.md` contains the full canonical version of the rule
2. `.github/copilot-instructions.md` summarizes it without contradiction
3. `fs-framework-general.mdc` references it without contradiction
4. The specialized `.mdc` expands on it without redefining the baseline
5. No file contradicts another on the same topic

## Example: Adding a New Shared Rule

Suppose we add "All new controllers must use Symfony Request":

```
1. AGENTS.md → Add to "Code Style Guidelines > Controllers" section
2. copilot-instructions.md → Add one-liner in conventions section
3. fs-framework-general.mdc → Add to "Baseline compartido" bullet list
4. fs-framework-symfony74.mdc → Add detailed examples and patterns
```

## Example: Adding a Specialized Rule

Suppose we add a new PHPUnit assertion helper:

```
1. fs-framework-testing.mdc → Add the helper pattern and example
   (no changes needed in AGENTS.md or other files)
```
