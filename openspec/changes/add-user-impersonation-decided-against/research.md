# Research — add-user-impersonation

**Schema**: `gentle-ai.sdd-research/v1`
**Revision**: 1
**Change**: `add-user-impersonation`
**Store**: openspec (workspace `openspec/config.yaml` → `persistence: openspec`)
**Outcome**: `blocked`

## Admission

| Field | Value |
|---|---|
| Capability declaration | `gentle-ai.sdd-research-capability/v1` |
| Requested evidence classes | `documentation`, `open-web` (plus local `vendor/` source read for Q4) |
| Observed grant — `documentation` | `[]` (none declared → denied) |
| Observed grant — `open-web` | `[]` (none declared → denied) |
| Declared grants for other classes | none |
| Admission result | **DENIED for every requested class** |

No class was admitted. Per the research lifecycle, an admission denial for the requested
source classes is fail-closed: this artifact records the denial and the retained intent,
and **emits no source claims**. No external source was consulted, so any answer to the five
research questions below would be unvalidated and is deliberately absent.

The capability declaration above is the only one found. A repository-wide search for a
capability/evidence-grant declaration (`sdd-research-capability`, `evidence_grants`,
`open-web`) returns exactly one hit — the empty-grant declaration in the executor launch
prompt (`opencode.json:211`). There is no competing declaration that would supply grants.

Runtime observation (not an evidence claim): the `documentation` class maps to the
Context7 tool available in this runtime; no open-web/fetch tool is present in the runtime
at all. Therefore even a future grant of `open-web` could not be satisfied in this
execution environment without a tool change — that is a runtime-capability fact, reported
so recovery is planned against reality.

## Selected intent (retained)

The following questions were selected and MUST be re-run unchanged once grants exist.
They are recorded verbatim in intent, not answered.

1. **Audit attribution for impersonation in established systems.** How do mature
   admin/ERP platforms record "actor acting as target" in an audit trail — dual
   attribution, an `acting_as` field, a distinct event type, or impersonator-as-actor with
   subject in context? What audit/compliance expectations exist for privileged access
   (ISO 27001 / SOC 2 style attributable-audit-trail and admin-action-logging
   requirements)? This must inform the concrete shape of D3.
2. **Session-level invariants established frameworks enforce.** Symfony `switch_user`
   (`SwitchUserToken`, `ROLE_PREVIOUS_ADMIN`, exit implementation, nested-switch
   prevention, fate of the impersonator's credentials) plus at least two other ecosystems
   (`django-impersonate`, `laravel-impersonate`/Filament, WordPress "login as user"
   plugins). Focus: how each preserves or destroys the impersonator's own session and
   cookies, and how leave/restore works.
3. **Read-only / "view as" impersonation.** Is there an established pattern for permitting
   navigation while blocking writes during impersonation (read-only mode, restricted
   token, voter/policy check)? How is it enforced centrally rather than per-action?
   Include `AuthenticatedVoter` / `IS_IMPERSONATOR` where applicable.
4. **Symfony applicability.** `vendor/symfony/security-core` ships `SwitchUserToken` and
   `AuthenticatedVoter`, but this app has no firewall. Determine from official Symfony
   documentation whether `switch_user` can be used without the full security
   bundle/firewall, and what adopting it would require. Local `vendor/` source is
   authorised as labelled local-source evidence for this question only.
5. **The cookie/session hazard.** Is "impersonation silently replaces the impersonator's
   persistent cookies, so a session loss restores the wrong user" documented as a known
   class of bug, and what is the accepted mitigation? This validates whether D4 is the
   standard answer or whether a better-known alternative exists.

## Sources

None. No admitted class; no source was retrieved or is cited.

## Validated claims

None. Blocked outcomes exclude unvalidated claims, and no class was admitted to validate
any claim. The five questions above remain **unanswered**.

## Contradictions

None assessable. Contradictions against D1–D4/D6 can only be established from admitted
evidence; with both requested classes denied, no contradiction could be checked either
way. The decisions are neither confirmed nor challenged by this artifact.

## Uncertainty and freshness

- **Uncertainty**: total, for all five questions.
- **Freshness**: not applicable — nothing retrieved.
- **Blocked reason**: evidence admission denial (`documentation=[]`, `open-web=[]`).
  This is an admission failure, not a research finding.

## Product choices (separate and non-authoritative)

Supplied by the orchestrator/product owner as fixed inputs; recorded here for retention
only. They are NOT research conclusions and this artifact does not validate them.

- **D1** — only non-admin users can be impersonated.
- **D2** — read-only "view as": target menu and permissions, writes blocked.
- **D3** — actor recorded in `fs_logs.usuario` is the admin; target identified in detail.
- **D4** — preserve the admin's legacy cookies and suppress the per-request re-stamp while
  impersonating.
- **D6** — no notice to the impersonated user; audit only.

## Recovery inputs (unverified pointers — not evidence, not claims)

These are locations named by the research request, listed only so a granted re-run starts
from the same place. Their content was **not read** in this blocked run and nothing is
asserted about them.

- `vendor/symfony/security-core` — named as holding `SwitchUserToken` / `AuthenticatedVoter` (Q4).
- `openspec/changes/add-user-impersonation-decided-against/exploration.md` — in-repo findings already
  measured; must be built upon, not repeated.

## Recovery path

To make this selected research runnable, the orchestrator must supply a capability
declaration `gentle-ai.sdd-research-capability/v1` with at least one of:

- `documentation: granted` (Context7 official docs), and/or
- `open-web: granted` (requires an open-web tool in the runtime; none is currently present).

Then re-select `sdd-research` for `add-user-impersonation` with the same five questions.
Until then, `proposal_ready` MUST remain false: the readiness matrix returns `no` for any
`blocked` outcome.
