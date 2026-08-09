# Daily Orchestration Checklists

## Risk Classification (before any gates run)

- [ ] Risk tier assigned: low / medium / high.
- [ ] Tier checked against `workflow.md`'s High-Risk Gates list, not guessed.
- [ ] Gates skipped by the tier are named explicitly, not silently omitted.
- [ ] Ambiguous cases default to medium, not low.

## Harness Readiness

- [ ] Agent invocation names validated against frontmatter `name:`.
- [ ] Agent calls use exact `subagent_type`.
- [ ] Foreground execution available.
- [ ] SendMessage continuation tested.
- [ ] Read-only restriction strategy selected.
- [ ] Per-call model selection confirmed or documented as unavailable.

## Before a Slice

- [ ] One bounded objective.
- [ ] Exact paths.
- [ ] Acceptance behavior.
- [ ] Targeted commands.
- [ ] Foreground developer agent.
- [ ] Agent/session ID retained.
- [ ] Reviewer model selected by risk.

## During Correction Loop

- [ ] Use SendMessage to original developer.
- [ ] Send delta only.
- [ ] Do not repeat full feature context.
- [ ] Rerun only affected checks first.
- [ ] Spawn a replacement only with a stated reason.

## Read-Only Gate

- [ ] Enforced tool restriction, or:
- [ ] HEAD captured.
- [ ] status captured.
- [ ] staged/unstaged diff captured.
- [ ] state compared after review.
- [ ] no unexpected mutation.

## High-Risk Review

- [ ] Strong reviewer model used.
- [ ] Runtime risks checked.
- [ ] deployment risks checked.
- [ ] rollback requirements checked.
- [ ] documentation aligned.
- [ ] blocker/high findings independently sanity-checked.

## Before Staging/Committing

`git add <directory>` is not proof that the full approved artifact set was staged —
repo-level ignore rules can silently drop files, especially new Markdown.

- [ ] For any newly created `*.md` file, checked whether a repo-level ignore rule
      (e.g. a blanket `*.md` entry) excludes it before assuming a plain `git add` staged it.
- [ ] After staging, confirmed the staged file count and names with
      `git diff --cached --name-status` and `git diff --cached --stat` — compared
      against the approved file list, not just glanced at.
- [ ] `git add -f <file>` used only for the specific, explicitly approved files an
      ignore rule is blocking — never as a blanket workaround.
- [ ] `git diff --cached --check` run before committing (whitespace/conflict-marker check).
- [ ] Commit message reviewed against the actual staged diff, not the intended one.
