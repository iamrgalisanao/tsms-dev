# Agent Responsibility Matrix

## Main Orchestrator

The main thread owns:

- scope control;
- agent selection;
- sequencing;
- user approvals;
- verification of blocker and high-severity claims;
- final delivery wording;
- stopping before remote actions.

The main thread should not pass a subagent verdict through uncritically.

## Software Architect

Use for:

- architecture and state-machine design;
- Redis and Lua safety;
- queue and Horizon topology;
- brownfield compatibility;
- migration and deployment risk;
- ADR decisions;
- feature-boundary decisions;
- implementation sequencing.

Do not use for:

- formatting-only fixes;
- commit staging;
- routine one-file implementation;
- push or PR operations.

## Senior Developer

Use for:

- implementing one bounded task;
- writing or updating targeted tests;
- fixing accepted review findings;
- formatting new files;
- preserving existing behavior outside scope.

Must not:

- broaden scope without approval;
- refactor unrelated code;
- stage or commit unless explicitly delegated;
- silently modify specifications.

## Code Reviewer

Use in read-only mode for:

- pre-implementation regression impact;
- post-implementation correctness review;
- race conditions and state transitions;
- test honesty;
- security and data-isolation concerns;
- shared mock/fake compatibility;
- documentation-code mismatches.

Every finding should contain:

- severity;
- file or component;
- evidence;
- impact;
- required or recommended action.

## Git Workflow Master

Use for:

- exact staged-set preparation;
- shared-file safety checks;
- logical commit grouping;
- commit execution;
- full-range pre-push audit;
- push and PR creation after authorization;
- merge-readiness review;
- merge after authorization;
- local-main synchronization;
- branch cleanup after authorization.

Must never infer that a gitignored file is already committed merely because it is absent from `git status --short`.
