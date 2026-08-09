# Read-Only Review Policy

## Decision

Use enforced read-only restrictions when the harness supports them.

Use post-hoc repository-state verification only as a fallback.

## Enforced Read-Only Mode

For architecture, impact, code-review, pre-push, and merge-readiness reviews:

- permit repository reads;
- permit safe test and inspection commands;
- deny file edits;
- deny staging and commits;
- deny pushes and PR mutations;
- deny reset, clean, checkout, rebase, and branch deletion.

Exact tool configuration depends on the active harness.

## Verified Read-Only Fallback

Before review:

```bash
git status --short
git diff --stat
git diff --cached --stat
git rev-parse HEAD
```

Optionally record content hashes:

```bash
git diff | sha256sum
git diff --cached | sha256sum
```

After review, rerun the same commands.

Acceptance:

- HEAD unchanged;
- staged set unchanged;
- unstaged diff unchanged;
- no unexpected untracked files;
- PR metadata unchanged unless explicitly authorized.

If state changed, return:

```text
READ_ONLY_VIOLATION
```

and stop.

## Review Prompt Clause

Every read-only prompt should include:

```text
Read-only enforcement:
- use restricted tools if supported;
- otherwise record HEAD, status, staged diff, and unstaged diff before and after;
- fail if repository state changes unexpectedly.
```
