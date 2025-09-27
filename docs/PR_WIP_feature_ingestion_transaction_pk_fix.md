# PR: feature/ingestion-transaction-pk-fix (WIP branch)

This document accompanies the WIP application code branch for the ingestion and validation improvements (service, job, model and test changes). IMPORTANT: this branch must be merged only after the migration-only branch (`feature/ingestion-migrations`) has been applied to the target environment (staging/production) and workers restarted.

Purpose
- Preserve raw inbound payloads on ingestion and add reconciliation helpers.
- Canonicalize child rows to reference `transactions.id` (via `transaction_pk`).
- Make validation audit-first: create `security_events` rows on validation/audit events.

Important merge rule (READ BEFORE MERGE)
1. This PR MUST NOT be merged before the database migrations in `feature/ingestion-migrations` are applied on the target environment.
2. The migration-first rollout avoids application errors where code writes to columns or enum values that don't exist yet.
3. Before merging, confirm all of the following:
   - A backup/snapshot of the target database has been taken.
   - `php artisan migrate --force` has been run on the target environment and completed without errors.
   - Background workers have been gracefully restarted (for Horizon: `php artisan horizon:terminate`; otherwise: `php artisan queue:restart`).

Staging verification (evidence)
The following verification was executed on staging to confirm the `security_events` table exists and that the application DB user can INSERT into it safely (an INSERT inside a transaction was ROLLED BACK on purpose to avoid leaving test data).

DESCRIBE security_events output (abridged):

```
Field          | Type              | Null | Key | Extra
-----------------------------------------------------
id             | bigint unsigned   | NO   | PRI | auto_increment
tenant_id      | bigint unsigned   | YES  | MUL |
event_type     | varchar(191)      | NO   |     |
severity       | varchar(191)      | YES  |     |
user_id        | bigint unsigned   | YES  | MUL |
source_ip      | varchar(191)      | YES  |     |
context        | json              | YES  |     |
event_timestamp| timestamp         | YES  |     |
description    | text              | YES  |     |
created_at     | timestamp         | YES  |     |
updated_at     | timestamp         | YES  |     |
```

Safe write probe result (one-line Laravel tinker probe executed on staging):

```
WRITE_OK_ROLLED_BACK
```

This proves the staging DB user can INSERT into `security_events` and that the probe rolled back successfully.

How to open the PR
- Checkout the WIP branch locally (replace the branch name below with the actual WIP branch if different):

```bash
git fetch origin
git checkout -b feature/ingestion-transaction-pk-fix origin/feature/ingestion-transaction-pk-fix
```

- Push the branch (if not already pushed):

```bash
git push origin feature/ingestion-transaction-pk-fix
```

- Create a PR in GitHub from that branch into your target branch (staging or main); include a short summary and link to this file.

Suggested PR description snippet (copy into PR body):

> This PR contains the application, service, job and test changes required for the ingestion/validation improvements. NOTE: merge only after the migration-only branch `feature/ingestion-migrations` has been applied to the target environment and workers restarted. See `docs/PR_WIP_feature_ingestion_transaction_pk_fix.md` for verification evidence and merge checklist.

Checklist for the reviewer / operator
- [ ] Confirm DB backup/snapshot has been taken and stored.
- [ ] Confirm `feature/ingestion-migrations` migrations were applied on the target environment.
- [ ] Confirm workers were restarted after migrations applied.
- [ ] Run a smoke test: create a small transaction and process one queued job; confirm `transaction_validations` and `security_events` entries are created.

Optional: I can prepare a one-shot script (backup -> migrate -> deploy WIP -> restart -> smoke test) for the operator. Reply and I will create it and place it in `scripts/` with placeholders for credentials.

---
Document created to assist reviewers and operators. Include this file in the PR or link to it in the PR description.
