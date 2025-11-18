## Simpler Forwarding Service — Implementation Plan

Status: draft

Last updated: 2025-11-15

Overview
--------
This document describes a compact, pragmatic design to forward persisted TSMS transactions to the Webapp for reporting. It focuses on minimal payloads, simple idempotency/dedupe semantics, resilient delivery with retries and a dead-letter path, and a small set of DB changes to track forwarding state.

Goals
-----
- Keep messages small and targeted for reporting needs.
- Make forwarding asynchronous and non-blocking for ingestion.
- Ensure idempotent delivery with simple dedupe keys.
- Provide clear failure handling and operator recovery (DLQ + reprocess).
- Add minimal DB changes to enable visibility and retries.

Design principles
-----------------
- TSMS is source-of-truth: forwarded messages are copies for reporting only.
- Forward only fields that the Webapp actually needs.
- Use batch forwards to reduce overhead and improve throughput.
- Keep idempotency deterministic: dedupe keys based on (transaction_id, terminal_id) or `tsms_id`.
- Always persist forwarding metadata to enable operator introspection and replays.

Minimal payload (recommended)
------------------------------
Only send the fields necessary for reporting and joins. Example minimal transaction object:

```json
{
  "transaction_id": "TX-0001",
  "tsms_id": 987654,
  "submission_uuid": "...",
  "tenant_id": 123,
  "terminal_id": 45,
  "receipt_no": "ABC-123",
  "transaction_timestamp": "2025-11-15T12:34:56.123Z",
  "gross_sales": 1234.50,
  "net_sales": 1100.00,
  "vat": 134.50,
  "validation_status": "VALID",
  "correlation_id": "uuid-...",
  "created_at": "2025-11-15T12:34:58.000Z"
}
```

Notes:
- Use ISO-8601 UTC timestamps with millisecond precision when available.
- Do not forward raw consumer PII unless explicitly required; redact if included.

Transport contract (Webapp endpoint)
-----------------------------------
- Endpoint: POST /api/integrations/tsms/transactions/bulk
- Headers:
  - Authorization: Bearer <TSMS-FORWARDING-TOKEN>
  - Content-Type: application/json
  - Idempotency-Key: <batch_uuid> (optional, server may dedupe by transaction keys)
- Body:
  {
    "source": "tsms",
    "batch_id": "<uuid>",
    "transactions": [ { ... }, { ... } ]
  }
- Response success (200/202): { accepted: N, rejected: M, details: [{transaction_id, error?}] }

Forwarding flow (TSMS side)
---------------------------
1. After successful DB commit for transactions (storeOfficial/processTransaction), enqueue a ForwardTransactionsJob with a batch of transaction IDs (or dispatch a smaller job per submission that groups transactions into batches).
2. Worker picks job -> collects transaction rows -> builds the minimal payload.
3. POST to Webapp bulk endpoint with Authorization header and Idempotency-Key (batch id).
4. On success (2xx): mark forwarded transactions as `forward_status = 'sent'` and set `last_forwarded_at`.
5. On transient error (5xx, timeout): throw to let queue retry (Laravel job retries/backoff). Track attempt count.
6. On permanent error (4xx client error or fatal JSON error): mark `forward_status = 'failed'` and record `forward_error` for operator review; optionally move to DLQ.

Delivery & retry policy
-----------------------
- Use Laravel queue jobs with `tries` and `backoff()` configured (e.g., tries=5 with exponential backoff: 10s, 30s, 120s, 10m, 30m).
- Use jitter to prevent thundering herd.
- After the final retry, persist error into a DLQ table (or set `forward_status='failed'`) and create an operator notification (Horizon alert, metric, or event).
- Jobs should be idempotent: re-posting same transaction(s) must be safe for Webapp (webapp should dedupe on transaction_id+terminal_id).

Idempotency & dedupe
--------------------
- Primary dedupe key: (transaction_id, terminal_id). If `transaction_id` is not guaranteed unique across terminals, use (submission_uuid, transaction_id) or `tsms_id` where available.
- Use Idempotency-Key per batch to help the Webapp dedupe entire batches.
- On TSMS side: don't mark `forward_status='sent'` until Webapp returns success. For partial acceptance, update per-transaction status according to the response details.

Failure handling & operator UX
------------------------------
- Persist `forward_status` and `forward_attempts` per transaction (or use a separate `transaction_forwards` table to store history).
- Provide an admin list for failed forwards with actions:
  - Retry selected transactions/batches
  - View last error and last HTTP response
  - Re-generate a forward payload for manual POST (for debugging)
- Send an alert when DLQ size exceeds threshold or per-tenant failure rate spikes.

Database changes (recommended)
-----------------------------
Option A: Add columns to `transactions` (small/simple)
- migration:

```php
Schema::table('transactions', function (Blueprint $table) {
    $table->enum('forward_status', ['pending','sent','failed'])->default('pending')->index();
    $table->unsignedSmallInteger('forward_attempts')->default(0);
    $table->text('forward_error')->nullable();
    $table->timestamp('last_forwarded_at')->nullable();
});
```

Option B: Separate `transaction_forwards` table (more flexible, recommended for history)

```php
Schema::create('transaction_forwards', function (Blueprint $table) {
  $table->id();
  $table->foreignId('transaction_id')->constrained('transactions');
  $table->uuid('batch_id')->nullable()->index();
  $table->enum('status', ['pending','sent','failed'])->default('pending')->index();
  $table->unsignedSmallInteger('attempt')->default(0);
  $table->text('error')->nullable();
  $table->json('response')->nullable();
  $table->timestamps();
});
```

Tradeoff: Option A is quicker; Option B gives full history and makes operator tooling easier.

Implementation checklist (developer tasks)
----------------------------------------
1. Migration: add forward columns or create `transaction_forwards` table (pick Option B if uncertain).
2. Config: add `services.webapp.forwarding_url` and `services.webapp.forwarding_token` to `config/services.php` and `.env` (e.g., WEBAPP_FORWARDING_URL, WEBAPP_FORWARDING_TOKEN).
3. Job: create `ForwardTransactionsJob` with constructor(array $transactionIds, $batchId = null) and handle() that implements batching and HTTP POST with retries.
4. Dispatch: after DB commit in `storeOfficial` and/or transaction creation flow, dispatch job(s) with transaction ids (chunked). Prefer `dispatchAfterCommit`.
5. Status updates: job updates `transaction_forwards` or `transactions` columns atomically on success/failure.
6. Tests: unit tests for job logic, including partial success responses and retry behavior; integration test that mocks Webapp.
7. Admin UI: small page to list failed forwards and allow retries (leverages existing admin roles).
8. Metrics: increment counters in job (forward_success_total, forward_failure_total, forward_latency_seconds) using the app's metrics system.

Example ForwardTransactionsJob pseudocode
----------------------------------------
1. Accept list of $transactionIds and optional $batchId.
2. Load transactions from DB and build minimal payloads.
3. POST to `config('services.webapp.forwarding_url')` with Authorization header.
4. If HTTP 2xx: mark as sent, store response, set last_forwarded_at.
5. If HTTP 4xx: mark as failed (don't retry automatically), store response.
6. If HTTP 5xx/timeout: throw exception to trigger Laravel job retry logic.

Security
--------
- Use a dedicated, scoped API token for TSMS -> Webapp forwarding.
- Validate the Webapp TLS certificate (default system CA) or use mTLS for increased security.
- Avoid forwarding secrets. If raw payloads are needed for forensic reasons, store them in an encrypted archive and forward only minimal fields.

Metrics & alerts
----------------
- Implement counters and histograms: forward_attempts_total, forward_success_total, forward_failure_total, forward_latency_seconds.
- Alert rules:
  - forward_failure_rate > 5% for 10m
  - DLQ size > threshold (e.g., 100 items)

Testing & validation
--------------------
- Unit tests for payload builder (ensures minimal fields are constructed correctly, timestamps in UTC with ms precision).
- Job tests that simulate 200/202, 4xx and 5xx responses.
- Integration test that runs job against a local mock Webapp endpoint and asserts DB forward status updates.

Rollout plan
------------
1. Implement migration & job (dev branch). Run DB migration in staging.
2. Deploy job code to staging, enable forwarding via configuration pointing to a mock Webapp.
3. Run end-to-end flow with small batches; validate success, errors, retries, and operator UI.
4. Switch forwarding token to real Webapp and rollout to production in a controlled window. Monitor metrics and DLQ.

Staging VM (Ubuntu) deployment notes — no herd
------------------------------------------------
Context: your staging environment is a remote virtual machine running Ubuntu and does not use the `herd` process manager. Below are concrete, minimal steps and recommended service units to run queue workers, scheduling, and the forwarding job reliably on that VM.

Prerequisites on the VM
- Install system packages: git, php (matching your app), php-cli, php-fpm, php-xml, php-mbstring, php-pdo, php-zip, php-curl, php-intl, composer, mysql-client or psql client, redis-server (or use managed Redis), nginx or apache, and supervisor/systemd (systemd is present by default on modern Ubuntu).
- Ensure Redis is available and secured for Horizon/queue usage.
- Create an application user (e.g., `tsms`) and deploy code under `/var/www/tsms` or your chosen path.

Deploy steps (example)
1. SSH into staging VM.
2. Pull code and switch to branch (e.g., `staging`):

```bash
cd /var/www/tsms
git fetch origin
git checkout staging
git pull origin staging
```

3. Install PHP deps and build (server only needs composer for backend):

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --quiet
cp .env.example .env   # or ensure .env is provisioned from secrets
# Edit .env: set WEBAPP_FORWARDING_URL, WEBAPP_FORWARDING_TOKEN, queue connection, redis, etc.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

4. Ensure `.env` contains forwarding config and queue settings:

```ini
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
WEBAPP_FORWARDING_URL=https://webapp.example/api/integrations/tsms/transactions/bulk
WEBAPP_FORWARDING_TOKEN=secret_token_here
```

Queue worker & scheduler (systemd examples)
------------------------------------------
Prefer systemd units on a single VM (no herd). Examples below launch a queue worker dedicated to the forwarding queue and a scheduler.

Create `/etc/systemd/system/tsms-queue-forwarding.service`:

```ini
[Unit]
Description=TSMS Forwarding Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/tsms
ExecStart=/usr/bin/php /var/www/tsms/artisan queue:work redis --queue=forwarding,default --sleep=3 --tries=3 --timeout=120 --memory=256
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=tsms-queue-forwarding

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/tsms-scheduler.service` to run the Laravel scheduler via cron replacement:

```ini
[Unit]
Description=TSMS Scheduler
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=60
WorkingDirectory=/var/www/tsms
ExecStart=/usr/bin/php /var/www/tsms/artisan schedule:run --verbose --no-interaction
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=tsms-scheduler

[Install]
WantedBy=multi-user.target
```

Enable & start services:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tsms-queue-forwarding.service
sudo systemctl enable --now tsms-scheduler.service
```

Notes:
- The `queue:work` flags above are tuned for a single VM. Adjust `--memory` and `--timeout` according to the environment. Use `--queue=forwarding` to prioritize forwarding jobs; include `default` as a fallback.
- If you prefer Supervisor instead of systemd, configure `supervisord` with similar commands; systemd is preferred on modern Ubuntu for simplicity and integration.
- Don't run `php artisan queue:listen` on production; use `queue:work` and systemd/supervisor to manage lifecycle.

Resource & safety recommendations
---------------------------------
- Start with low concurrency on forwarding workers to avoid saturating the VM or webapp: use a single worker process (systemd unit above). If load increases, add a second unit with a smaller queue or increase memory/timeout.
- Limit batch size in the job (e.g., 50–200 transactions per HTTP request) to avoid HTTP timeouts and big memory spikes.
- Rotate logs and configure syslog/journald retention. Monitor disk space to avoid full disk issues on staging VM.

Testing on the VM
-----------------
- Use a mock Webapp endpoint (local nginx + small JSON responder or use httpbin) and set `WEBAPP_FORWARDING_URL` to it when testing.
- Run the job manually for quick tests:

```bash
php artisan tinker
>>> dispatch(new \App\Jobs\ForwardTransactionsJob([1,2,3], Str::uuid()));
```

or run the job's handle method locally to see logs.


OpenAPI / sample request (for Webapp)
-----------------------------------
POST /api/integrations/tsms/transactions/bulk

Request body (JSON):

```json
{
  "source": "tsms",
  "batch_id": "11111111-2222-3333-4444-555555555555",
  "transactions": [ { ... }, { ... } ]
}
```

Response (200):

```json
{ "accepted": 50, "rejected": 0, "details": [] }
```

Follow-ups (optional enhancements)
---------------------------------
- Add an export endpoint for large nightly exports if real-time forwarding becomes costly.
- Consider switching to an event bus (Kafka/SQS) if many consumers will subscribe to transactions.
- Add a forwarding healthcheck endpoint and a small Prometheus exporter for forwarding metrics.

Appendix — implementation priorities
-----------------------------------
P0 (must): Migration, ForwardTransactionsJob skeleton, dispatch after commit, webapp config, basic tests
P1: Admin UI for failed forwards, metrics instrumentation
P2: DLQ replayer, Op alerts, contract tests with Webapp
P3: mTLS or signed payloads, export endpoint or event bus

Contact / references
--------------------
- See `docs/WEBAPP_FORWARDING_INTEGRATION_GUIDELINES.md` for upstream Webapp expectations and sample payloads.

---

End of plan.
