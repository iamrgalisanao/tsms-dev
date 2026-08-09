# Token Optimization Without Quality Loss

## Principle

Token optimization means reducing repeated context and unnecessary output, not reducing precision.

A short vague prompt is not optimized. A compact prompt with exact scope, checks, exclusions, and output format is optimized.

## Prompt Compression Model

Each agent prompt should contain only six blocks:

```text
Objective
Scope
Required outcome
Verification
Constraints
Return format
```

## Context Budgeting

### Include

- exact feature or task ID;
- exact file paths;
- key invariant;
- prior decision that directly affects the task;
- exact commands required for verification;
- explicit exclusions.

### Omit

- full project history;
- repeated architecture narrative;
- prior test logs unless needed to compare a failure;
- generic instructions already defined in `CLAUDE.md`;
- explanations of why the agent is useful;
- optional work outside the current gate.

## Delta-Only Follow-Ups

After an agent reports a failure, the next prompt should contain only:

- the failed check;
- the affected files;
- the accepted baseline;
- the required correction;
- the rerun commands.

Example:

```text
Fix only Pint violations in:
- tests/Feature/FooTest.php
- tests/Unit/BarTest.php

Do not change test behavior.
Rerun Pint and the two targeted tests.
Return only files changed, results, and READY/NOT_READY.
```

## Read-Only First

For unfamiliar or high-risk areas:

1. inspect;
2. decide;
3. implement.

Do not combine exploration and implementation when the architecture is uncertain.

## Test Selection

Use a verification ladder:

1. syntax/lint for changed files;
2. focused unit tests;
3. focused feature tests;
4. static regression guards;
5. related integration suites;
6. full suite only when justified.

## Output Discipline

Preferred output:

```text
## Decision
READY | NOT_READY

## Findings
- concise evidence

## Verification
- command: result
```

Avoid:

- long narratives;
- reprinting entire diffs;
- generic praise;
- speculative future work;
- repeated summaries.

## Quality Safeguards

Token optimization must not remove:

- explicit scope;
- test commands;
- risk checks;
- acceptance criteria;
- stop conditions;
- evidence for findings;
- remote-action boundaries.
