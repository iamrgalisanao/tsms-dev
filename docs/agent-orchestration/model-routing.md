# Model Routing Policy

## Goal

Spend high-cost reasoning tokens only where error cost is high.

## Routing

| Work | Preferred model |
|---|---|
| File discovery, status checks, formatting | Haiku or lowest reliable model |
| Scoped implementation | Sonnet |
| Routine regression review | Sonnet |
| Architecture for isolated feature | Sonnet |
| Redis/Lua/state machine | Opus |
| Auth, tenancy, data isolation | Opus |
| Migration and production rollout risk | Opus |
| Financial/reporting correctness | Opus |
| Final pre-push audit for high-risk feature | Opus |
| Merge-readiness audit | Opus when branch divergence or governance complexity exists |
| Git execution after approval | Sonnet |

## Escalation Triggers

Escalate to Opus when any apply:

- cross-tenant data risk;
- financial correctness;
- irreversible migration;
- concurrency or atomicity;
- distributed queues;
- auth/authorization;
- ambiguous brownfield behavior;
- conflicting reviewer findings;
- pre-push audit covers multiple infrastructure layers.

## De-escalation

After the high-risk decision is made:

- implementation can return to Sonnet;
- formatting and mechanical fixes can use Haiku;
- Git execution can use Sonnet;
- do not keep Opus attached to routine follow-ups without need.
