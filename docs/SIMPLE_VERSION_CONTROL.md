# Simple Version Control Guide (solo developer)

Purpose
-------
This is a compact, no-friction Git workflow for a single developer working on TSMS. It focuses on clarity, easy rollbacks, and low overhead while keeping a clean history for future collaboration.

Principles
----------
- Keep `main` (or `production`) always deployable.
- Work in short-lived feature branches and merge back often.
- Use clear, concise commit messages so history is useful later.
- Tag releases with semantic version tags when you deploy.

Branching (very small)
----------------------
- `main` — always deployable. Pushes here represent releases or stable snapshots.
- `staging` — optional: use for integration/testing before promoting to `main`.
- `feature/<name>` — branch off `staging` (or `main`) for any work. Keep small and focused.
- `hotfix/<name>` — branch from `main` to fix urgent production issues; merge back to `staging`/`main`.

Typical flow (solo)
-------------------
1. Create a branch for your task:

```bash
git checkout -b feature/add-hourly-summary
```

2. Work and commit frequently with meaningful messages (see Commit messages).
3. Push the branch when you want a remote backup or to trigger CI:

```bash
git push -u origin feature/add-hourly-summary
```

4. Merge to `staging` (or `main`) when the feature is done. For small teams/solo devs, you can fast-forward merge:

```bash
git checkout staging
git pull
git merge --no-ff feature/add-hourly-summary
git push origin staging
```

5. Deploy from `staging` to your staging environment; after verification, merge `staging` into `main` and tag a release.

Commit messages (simple rules)
-----------------------------
- Keep commits small and focused.
- Use this compact format: `type: short description` where `type` is one of `feat`, `fix`, `docs`, `chore`, `test`, `refactor`.
- Example:

```
feat(reporting): add transactions_hourly migration
fix(ui): highlight WITH_ISSUES rows
docs: add reporting runbook
```

Why: consistent, searchable history; enough structure without heavy tooling.

Tags & releases (manual)
------------------------
- When you want to mark a release, tag `main` with a semantic version:

```bash
git checkout main
git pull
git tag -a v1.2.0 -m "Release v1.2.0"
git push origin v1.2.0
```

- Keep changelog notes in `CHANGELOG.md` or use annotated tags for quick reference.

Backups & remote
-----------------
- Always push branches frequently to `origin` as a simple offsite backup.
- Consider mirroring the repo to a private internal Git server if your project needs extra redundancy.

Database migrations & data
--------------------------
- Track schema changes with Laravel migrations (`database/migrations/`).
- Before running migrations on staging/prod, create a DB backup (dump) and note the migration version.
- If a migration is destructive or long-running, follow a multi-step deploy (add columns, backfill, swap reads, drop old columns).

Simple safety checks
--------------------
- Run tests locally before merging:

```bash
php artisan test
```

- Optional: use a lightweight GitHub Action to run tests on push to `staging`/`main` so CI catches regressions. This is optional for solo devs but useful as a safety net.

Lightweight tooling (optional)
-----------------------------
- Pre-commit hooks (recommended but optional): run linters or tests before commits using `pre-commit` or `husky`.
- Commit message hook: optionally enforce the simple `type: message` format with a small script.

Emergency rollback (fast)
------------------------
- If a bad commit lands on `main`, create a revert commit and push:

```bash
git revert <bad-commit-sha>
git push origin main
```

Alternatively, if necessary, reset `main` to a previous tag and force-push (use carefully):

```bash
git checkout main
git reset --hard v1.1.0
git push --force origin main
```

Notes for later team growth
--------------------------
- If the team grows, move to protected branch rules, require PR reviews, and add CI checks. The simple conventions above map cleanly to a stricter workflow.

Where to put this guidance
--------------------------
- This file is intentionally short and lives at `docs/SIMPLE_VERSION_CONTROL.md`. Share and update it as your process evolves.

Quick reference commands
------------------------
- Create feature branch: `git checkout -b feature/your-thing`
- Push branch: `git push -u origin feature/your-thing`
- Merge to staging: `git checkout staging && git merge --no-ff feature/your-thing && git push origin staging`
- Tag release: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push origin vX.Y.Z`
