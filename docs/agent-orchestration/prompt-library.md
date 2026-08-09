# Claude Agent Prompt Library

## Invocation Header

Every prompt begins with harness-level metadata outside the natural-language prompt:

```text
subagent_type: <exact frontmatter `name:` value>
execution: foreground
model: <risk-appropriate model>
```

The natural-language prompt should not substitute for correct tool configuration.

## 1. Feature Intake and Risk Classification

Runs on the main thread — do not delegate to a subagent. `agent-matrix.md` assigns scope control and sequencing to the orchestrator, not a subagent.

```text
Feature:
- <feature name, ticket, or raw request>

Classify change size
    ↓
Trivial/localized?
    ├─ Yes → skip Spec Kit → direct scope and risk classification
    └─ No  → locate or create Spec Kit feature
              → specify → clarify (if needed) → plan → tasks → analyze
              → risk classification → gate and agent selection

Use the exact Spec Kit commands installed in this repository. Inspect the
available command definitions or `.specify/` scripts rather than assuming
command spelling.

Before creating a new Spec Kit feature:
- search for an existing specification covering the same capability
- inspect repository naming and numbering conventions
- update an existing feature only when the request is within its documented boundary
- create a separate feature when the request crosses that boundary
- never silently absorb unrelated work into an existing user story

After tasks are generated or updated, run the Spec Kit consistency-analysis command.

If it identifies material requirement, plan, or task inconsistencies:
- return PLAN_UPDATE_REQUIRED
- list the exact inconsistencies
- do not begin implementation

Return PLAN_READY only when the artifacts are internally consistent or all
remaining findings are explicitly accepted as non-blocking.

Then classify risk:
- risk tier: low | medium | high (see workflow.md Risk-Based Gate Selection
  and High-Risk Gates)
- gates required by the tier, and gates explicitly skipped
- agent sequence
- commit groups
- remote-action stop points

Return only:
## Scope
## Spec-Kit Artifacts
<paths used, or "N/A — trivial change">
## Risk Classification
## Gates Selected
## Agent Sequence
## Verification Matrix
## Commit Groups
## Stop Points
## Decision
PLAN_READY | PLAN_UPDATE_REQUIRED
```

## 2. Architecture Review

```text
Objective:
Review the architecture for <feature/task>.

Scope:
- <exact paths>

Required outcome:
- approve, require changes, or reject
- identify runtime and deployment risks
- define implementation order and tests

Read-only enforcement:
- use restricted tools if supported
- otherwise snapshot HEAD/status/diffs before and after
- fail on unexpected repository changes

Return only:
## Decision
ARCHITECTURE_APPROVED | ARCHITECTURE_APPROVED_WITH_CHANGES | ARCHITECTURE_NOT_APPROVED
## Findings
## Implementation Order
## Required Tests
## Expected Files
```

Recommended invocation:

```text
subagent_type: Software Architect
execution: foreground
model: opus for high-risk work
```

## 3. Regression-Impact Review

```text
Objective:
Analyze regression impact for <task>.

Scope:
- target files
- callers and dependencies
- routes/middleware
- shared mocks/fakes
- configuration and queue dependencies
- related tests

Read-only enforcement:
- enforce restrictions or verify repository state before/after

Return only:
## Direct Change Surface
## Indirect Dependencies
## Regression Test Matrix
## Brownfield Risks
## Decision
READY_TO_IMPLEMENT | PLAN_UPDATE_REQUIRED
```

Recommended invocation:

```text
subagent_type: Code Reviewer
execution: foreground
model: opus for high-risk shared infrastructure; otherwise sonnet
```

## 4. Baseline Verification

```text
Objective:
Record the baseline for <task>.

Run exactly:
- <commands>

Classify each failure:
- passing baseline
- pre-existing failure
- environment issue
- feature-relevant blocker

Return only:
## Commands
## Results
## Accepted Baseline
## Decision
BASELINE_RECORDED | BASELINE_BLOCKED

Do not modify files.
```

Recommended invocation:

```text
subagent_type: Senior Developer
execution: foreground
model: sonnet
```

Low risk skips this step — see `workflow.md` Risk-Based Gate Selection.

## 5. Scoped Implementation

```text
Objective:
Implement only <task ID and title>.

Scope:
- <exact files>

Required outcome:
- <observable behavior>

Verify:
- <exact commands>

Constraints:
- preserve behavior outside scope
- no unrelated refactoring
- no stage/commit/push/PR
- stop if an architecture assumption is false

Return only:
## Files Changed
## Implementation Summary
## Verification Results
## Risks
## Decision
READY_FOR_REVIEW | NOT_READY
```

Recommended invocation:

```text
subagent_type: Senior Developer
execution: foreground
model: sonnet
```

Store the returned agent/session ID. Use it for correction messages.

## 6. Slice Correction via SendMessage

Send to the original developer agent session:

```text
Correction only.

Finding:
- <exact evidence>

Affected files:
- <exact files>

Required correction:
- <one bounded change>

Rerun:
- <exact commands>

Do not revisit unrelated design.
Return only:
## Files Changed
## Verification Results
## Decision
READY_FOR_REVIEW | NOT_READY
```

Do not launch a new developer agent unless the original session cannot continue.

## 7. Code Review

```text
Objective:
Review only <implemented scope>.

Check:
- correctness
- race conditions
- regressions
- data isolation
- shared infrastructure effects
- test honesty
- operational requirements

Read-only enforcement:
- enforce restrictions or verify repository state before/after

Return only:
## Decision
REVIEW_PASS | REVIEW_FINDINGS
## Findings
For each:
- severity
- file
- evidence
- impact
- action
## Test Gaps
```

`REVIEW_FINDINGS` covers any review result requiring correction, regardless of severity. Do not introduce a third terminal reviewer status for repeated failure — if `REVIEW_FINDINGS` recurs after correction, the orchestrator escalates (new agent, architecture re-review, or a stop-and-ask), rather than the reviewer encoding that as a distinct verdict.

Recommended invocation:

```text
subagent_type: Code Reviewer
execution: foreground
model: opus for Redis/Lua/auth/tenancy/migrations/finance; otherwise sonnet
```

## 8. Prepare Commit Group

```text
Objective:
Prepare and verify commit group <name>.

Stage exactly:
- <files>

Do not stage:
- <files/categories>

Run:
- <tests/checks>

Verify:
- exact staged set
- no mixed changes
- new files pass formatting
- legacy formatting debt remains out of scope

Return only:
## Staged Group
## Verification Results
## Shared-File Safety
## Decision
READY_FOR_COMMIT | NOT_READY

Do not commit or push.
```

Recommended invocation:

```text
subagent_type: Git Workflow Master
execution: foreground
model: sonnet
```

## 9. Pre-Push Audit

```text
Objective:
Perform a read-only pre-push audit.

Commit range:
<range>

Review:
- commit sequence
- full-range diff
- secrets/backups/debug/generated files
- implementation consistency
- runtime and deployment risks
- documentation accuracy
- deferred-scope honesty
- working-tree state

Read-only enforcement:
- enforce restrictions or verify repository state before/after

Return only:
## Executive Verdict
READY_TO_PUSH | NEEDS_FIXES | NEEDS_MANUAL_DECISION
## Commit Sequence Review
## Full-Range Diff Review
## Findings
## Verification Gaps
## Working Tree State
## Recommended Next Step
```

Recommended invocation:

```text
subagent_type: Git Workflow Master
execution: foreground
model: opus for high-risk features; otherwise sonnet
```

## 10. Merge-Readiness Review

```text
Objective:
Perform a read-only merge-readiness review for PR <number>.

Check:
- PR state and head
- fresh CI conclusions
- review policy
- base drift and conflicts
- commit/diff integrity
- deployment actions
- work-item/governance requirements

Read-only enforcement:
- enforce restrictions or verify repository and PR state before/after

Return only:
## Merge-Readiness Verdict
READY_TO_MERGE | NEEDS_FIXES | NEEDS_REBASE_OR_UPDATE | NEEDS_MANUAL_DECISION
## CI and Review Status
## Base Branch Drift
## Commit and Diff Integrity
## Deployment Actions
## Recommended Next Step
```

Recommended invocation:

```text
subagent_type: Git Workflow Master
execution: foreground
model: opus for high-risk features
```
