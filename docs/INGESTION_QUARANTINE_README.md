# Ingestion Quarantine - Operator Guide

This document explains the `ingestion:quarantine:*` artisan commands and the safety protections implemented around them.

Commands

- `php artisan ingestion:quarantine:list` — list recent quarantine records (safe, read-only)
  - Options: `--limit=100`, `--status=NEW`

- `php artisan ingestion:quarantine:show {id}` — view a quarantine record summary and a redacted payload (safe)
  - `--force` shows the full payload. Full payload display may be restricted by configuration and will prompt for confirmation.

- `php artisan ingestion:quarantine:replay {id}` — attempt to replay a quarantined payload back through the ingestion endpoint
  - By default this command performs a dry-run which only increments the attempt counter and marks the record processing.
  - `--execute` actually re-posts the payload in-process to `/api/v1/transactions/official` and will therefore run full ingestion logic.
  - Because `--execute` can have side effects (creating transactions, firing jobs), it is gated by configuration and interactive confirmation.
  - `--force` bypasses the config gate and confirmation prompt (use with caution).

Safety and policy

- Config flags live in `config/ingestion.php` under the `quarantine` key. The defaults are conservative (disabled):

```php
'quarantine' => [
    'allow_replay_execute' => env('TSMS_INGESTION_QUARANTINE_ALLOW_REPLAY', false),
    'allow_show_full_payload' => env('TSMS_INGESTION_QUARANTINE_ALLOW_SHOW_FULL', false),
    'retention_days' => (int) env('TSMS_INGESTION_QUARANTINE_RETENTION_DAYS', 30),
]
```

- Recommended production practice:
  - Keep `allow_replay_execute=false` in production unless you have a documented pilot and operator SOP.
  - Allow only trusted operators to run `--execute` replays.
  - Use the `--force` flag only when you understand the implications and have backups/monitoring.

Security and PII

- Quarantined payloads often contain PII or payment metadata. Treat them as sensitive:
  - Limit who can access production servers and who can run the `show --force` or `replay --execute` commands.
  - Consider enabling `allow_show_full_payload=false` and using the `show` redaction by default.
  - Consider encrypting the `payload` column if you store sensitive data long-term.

Housekeeping

- Retention: use `quarantine.retention_days` to plan purge jobs (default 30 days).
- Implement a scheduled Artisan command (e.g., `ingestion:quarantine:purge`) to delete aged rows safely.

Examples

List 25 NEW quarantine rows:

```bash
php artisan ingestion:quarantine:list --status=NEW --limit=25
```

Show a quarantined payload (redacted):

```bash
php artisan ingestion:quarantine:show 42
```

Show the full payload (will prompt unless configuration allows full display):

```bash
php artisan ingestion:quarantine:show 42 --force
```

Dry-run a replay (safe):

```bash
php artisan ingestion:quarantine:replay 42
```

Execute a replay (dangerous):

```bash
# safest: enable policy flag and then execute
export TSMS_INGESTION_QUARANTINE_ALLOW_REPLAY=true
php artisan ingestion:quarantine:replay 42 --execute

# OR, override interactively
php artisan ingestion:quarantine:replay 42 --execute --force
```

Contact / Ownership

- Responsible team: Platform / Ops
- For questions or to request the replay capability be enabled in a tenant/cluster, file a ticket with justification and rollback plan.
