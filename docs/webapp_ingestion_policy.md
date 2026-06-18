# WebApp Ingestion Policy

Status: recommended default for passive ingestion of TSMS-forwarded payloads
Date: 2025-09-27

This document describes the minimal, safe policy the WebApp should implement when receiving transaction envelopes forwarded by TSMS. TSMS is treated as the canonical producer: it validates and persists transactions locally and forwards the unmodified payload to the WebApp over an authenticated API.

Principles
- Passive ingestion by default — store exactly what the producer sent. Do not mutate monetary values or recompute aggregates during the ingestion path.
- Non-destructive verification — checksum verification, if enabled, runs in capture-only mode by default (log + metric) and must not reject valid producer payloads without a coordinated rollout.
- Auditability — persist original payload JSON and producer-provided checksums so mismatches can be triaged later.

Endpoint & Authorization
- Endpoint: POST /api/transactions/bulk (accepts single `transaction` or `transactions[]` envelope)
- Enforce TLS and certificate verification in production.
- Bearer token auth (compare to `config('tsms.web_app.auth_token')`). Consider IP allowlist or mTLS for additional safety.

Idempotency
- Use `submission_uuid` and `transaction_id` to detect and ignore duplicate submissions. Persist an ingestion record table with `submission_uuid`, `transaction_id`, `status`, and `received_at`.

Storage
- Map incoming fields directly to DB columns; do not recompute values or round on ingest.
- Persist `original_payload` (JSON), `forwarder_payload_checksum` (transaction-level), and `submission_payload_checksum` (submission-level) for audit/replay.

Canonical field names (usage in this document)
- transaction-level checksum: `payload_checksum` (the per-transaction SHA-256 hash)
- submission-level checksum: `submission_payload_checksum` (the SHA-256 hash for the submission/envelope)
- Note: TSMS historically uses `payload_checksum` at both levels in its envelopes; the WebApp should store the submission-level checksum as `submission_payload_checksum` in DB to avoid ambiguity. When returning or logging use explicit labels (transaction_payload_checksum / submission_payload_checksum).

Checksum handling (recommended)
- Configurable modes: `off` | `capture` | `enforce`. Start with `capture`.
- Mirror producer canonicalization when recomputing checksums: sort associative keys, preserve numeric array order, cast monetary-like keys to floats, JSON encode with JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE, then SHA-256 hex.
  
	- Canonical monetary key list (exact): ['gross_sales', 'net_sales', 'amount', 'vatable_sales', 'vat_amount', 'sc_vat_exempt_sales']
		- Note: this explicit list should be kept in sync with the producer's canonicalization logic (for example `app/Services/PayloadChecksumService.php` in TSMS). Put the list in config for both producer and consumer so it can be updated atomically when the canonicalization rules change.
- IMPORTANT: when recomputing per-transaction checksum, remove the transaction's checksum field before hashing. Remove the explicit top-level `sc_vat_exempt_sales` only if the producer excluded it when computing (the forwarder historically unsets the explicit top-level convenience field prior to per-transaction checksum).
- Capture mode: log mismatches with submission_uuid, transaction_id, provided_checksum, computed_checksum, and increment `webapp.ingest.checksum.mismatch` metric.

Validation & Errors
- Validate envelope shape; return 400 for malformed payloads (missing required fields). Do not recompute or reject for numeric formatting differences in capture mode.
- Return 401 for auth failures.

Metrics & Logging
- Metrics: `webapp.ingest.count`, `webapp.ingest.auth_failures`, `webapp.ingest.invalid_payloads`, `webapp.ingest.checksum.verified`, `webapp.ingest.checksum.mismatch`, `webapp.ingest.duplicates`.
- Log contextual info on errors and checksum mismatches (submission_uuid, tenant_id, transaction_id).

Testing & Rollout
- Integration test: POST payloads produced by TSMS scripts (`scripts/test_4986_payload.php`, `scripts/sale_non_vat_payload.php`) and assert stored fields and original payload presence.
- Rollout plan: enable capture mode (24–72h) → analyze mismatches → coordinate with TSMS, then canary enforce for a small tenant set → global enforce with schema_version bump if needed.

Operational notes
- Keep the forwarder token in a secrets manager and rotate regularly.
- Do not alter the stored JSON payloads; use stored originals for any offline revalidation.

Acceptance checklist
- [ ] Endpoint implemented and protected by bearer token + TLS
- [ ] Idempotency check by `submission_uuid` in place
- [ ] Original payload persisted with provided checksums
- [ ] Capture-mode checksum verification implemented and logs mismatches
- [ ] Integration tests using TSMS sample generators exist

Questions / Next steps
- I can implement a feature-flagged capture-only verification patch (small controller/service change) and add integration tests using the TSMS scripts. Tell me if you want that added here as a PR.

Clarifications, tightenings, and operational guidance
These items close gaps raised by the WebApp implementers. They are intentionally prescriptive so consumers and producers reproduce checksum values deterministically and operate safely in production.

1) Required / optional fields (envelope + transaction)
- Envelope (submission) required fields:
	- `submission_uuid` (UUID, required)
	- `tenant_id` (integer, required)
	- `terminal_id` (integer, required)
	- `submission_timestamp` (ISO8601 UTC, required)
	- `transaction_count` (integer, required)
	- `payload_checksum` (SHA-256 hex, required)
	- `transactions` OR `transaction` (see single vs batch)

- Transaction object required fields:
	- `transaction_id` (UUID, required)
	- `transaction_timestamp` (ISO8601 UTC, required)
	- `gross_sales` (numeric/string, required)
	- `net_sales` (numeric/string, required)
	- `payload_checksum` (SHA-256 hex, required)
	- `items` (array, required where applicable)
	- `adjustments` (array, may be required by schema; see TSMS canonical schema)
	- `taxes` (array, may be required by schema)

- Optional/common fields (examples): `hardware_id`, `promo_status`, `customer_code`, `sc_vat_exempt_sales`

Link to canonical schema: prefer linking or embedding `TSMS_POS_Transaction_Payload_Guidelines_v2.md` (repo `_md/`), and keep the policy aligned with that canonical doc.

2) Two sample payloads (sanity-check vectors)

- Single-transaction example

```
{
	"payload_schema_version": "2.0",
	"submission_uuid": "11111111-2222-3333-4444-555555555555",
	"tenant_id": 10,
	"terminal_id": 101,
	"submission_timestamp": "2025-09-27T12:00:00Z",
	"transaction_count": 1,
	"transaction": {
		"transaction_id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
		"transaction_timestamp": "2025-09-27T11:59:55Z",
		"gross_sales": "1250.50",
		"net_sales": "1000.40",
		"payload_checksum": "<64-hex-tx-checksum>",
		"adjustments": [...],
		"taxes": [...]
	},
	"submission_payload_checksum": "<64-hex-submission-checksum>"
}
```

- Bulk example (2 transactions)

```
{
	"payload_schema_version": "2.0",
	"submission_uuid": "22222222-3333-4444-5555-666666666666",
	"tenant_id": 10,
	"terminal_id": 101,
	"submission_timestamp": "2025-09-27T12:10:00Z",
	"transaction_count": 2,
	"transactions": [ { /* transaction A */ }, { /* transaction B */ } ],
	"submission_payload_checksum": "<64-hex-submission-checksum>"
}
```

3) Numeric parsing and canonicalization edge cases (deterministic rules)
- Input normalization (pre-canonicalization):
	- Strip any grouping separators (commas) and currency symbols (e.g., `$`, `₱`) from numeric string inputs before parsing.
	- Empty or missing numeric fields: treat as NULL for storage but use `0.0` when the producer included a numeric field explicitly with zero value for checksum computation only when the producer does so; prefer trusting producer presence vs absence. Document and keep parity with producer behavior.
	- Explicit zero vs missing: if a key is absent, do not inject it prior to checksum; if present with `null` treat as `null` and canonicalize consistently.

- Casting & precision for checksum canonicalization:
	- Cast canonical monetary keys to a decimal string with a fixed scale of 2 decimal places (string form) before canonical JSON encoding (e.g., `number_format((float)$v, 2, '.', '')`). This avoids float-to-string formatting differences across runtimes.
	- Example: 1250.5 -> "1250.50"; 0 -> "0.00". Use this deterministic string representation during canonicalization and hashing.

4) Timestamp & timezone handling
- Accepted formats: strict ISO8601 UTC `YYYY-MM-DDTHH:MM:SSZ` or ISO8601 with timezone offset. Normalize to UTC before canonicalization and encode timestamps as `YYYY-MM-DDTHH:MM:SSZ` (no fractional seconds) for the canonical JSON used to compute checksums.

5) Behavior when `enforce` mode is enabled
- Exact failure semantics:
	- On checksum mismatch in `enforce` mode: respond with HTTP 422 (Unprocessable Entity) and body `{'success': false, 'message': 'Checksum validation failed', 'errors': [...]}`. Persist the original payload into an `ingestion_quarantine` table (or `transaction_submissions` with status `QUARANTINED`) for offline triage.
	- Send an alert (email/pager) to ops if mismatch rate for a tenant > configured threshold within a window (see observability thresholds).
	- Do not automatically requeue or retry the submission; manual triage required. Include a webhook or admin API to accept/release quarantined submissions after verification.

6) Idempotency collisions & race conditions
- Implementation guidance:
	- Enforce DB unique constraint on (`terminal_id`, `submission_uuid`) in the `transaction_submissions` table and on (`terminal_id`, `transaction_id`) in `transactions` (unique index). Use `INSERT ... ON CONFLICT DO NOTHING` / upsert semantics where supported.
	- Acquire a short-lived advisory lock or use a cache-based distributed lock keyed by `terminal_id:submission_uuid` to prevent duplicate concurrent processing across workers. Always treat the DB unique constraint as the source of truth.
	- On conflict during insert, read the existing row and return idempotent success (200) with the previously stored result; if the existing row payload_checksum differs, mark as drift/conflict and surface for manual reconciliation.

			- Suggested idempotent success response shape (on duplicate):

			```json
			{
				"success": true,
				"id": "<submission_uuid or transaction_id>",
				"status": "already_processed",
				"first_received_at": "2025-09-27T11:58:00Z",
				"note": "Idempotent replay - returning existing record"
			}
			```

7) Size, storage, and retention
- Recommendations:
	- Persist `original_payload` as compressed JSON (gzip) in a `jsonb` or blob column where possible, or store a hashed pointer if external object storage (S3) is preferred for older payloads.
	- Retention policy: keep full `original_payload` for 90 days by default, then either archive to cheap object storage or delete after 365 days unless flagged for audit. Provide a retention config `tsms.ingest.retention_days`.
	- Consider redaction and compression: strip or hash large binary blobs and redact high-risk PII before cold-archiving.

			- Recommended archival implementation pattern:
				- Store the most recent `original_payload` in the DB (compressed JSON blob) for `tsms.ingest.retention_days` (default 90).
				- When archiving, move the compressed JSON to S3 (or equivalent) using a stable key namespace `tsms/ingest/<year>/<month>/<submission_uuid>.json.gz`.
				- Store a DB metadata record with fields: `submission_uuid`, `tenant_id`, `terminal_id`, `s3_key`, `payload_size_bytes`, `submission_payload_checksum`, `archived_at`.
				- Use S3 lifecycle rules to transition to Glacier/Archive after 90 days and expire after 365 days (or longer if flagged for audit).

8) PII and security
- If payloads contain PII (customer names, phone numbers), either:
	- Redact those fields at ingestion then persist a redaction log (who/when), or
	- Encrypt `original_payload` at rest using a KMS-backed data key and store minimal metadata for search (tenant_id, transaction_id, submission_uuid).
	- Define an explicit list of PII fields in config for automated redaction.

			- Minimal required PII policy (default):
				- Fields considered PII: `customer_name`, `customer_phone`, `customer_email`, `customer_address`, `customer_id_number`.
				- Default behavior: field-level redaction at ingestion (replace values with `REDACTED`) for these fields in `original_payload` stored in DB, and store an encrypted copy of the full payload in S3 if `tsms.ingest.store_encrypted_payload=true`.
				- Encryption & KMS guidance: use a KMS-managed envelope key per environment. Rotate data keys every 90 days, require role-based access to decrypt, and log all key usage. Maintain an access audit trail (who/when/key-id) and allow ops to re-encrypt archived payloads when rotating keys.

9) Operational runbook (triage when mismatches spike)
- Quick steps:
	1. Verify production toggle: confirm `tsms.ingest.checksum_mode` equals `capture` or `enforce` and which tenants are in canary.
	2. Run sample re-computation using the canonicalization script on a small sample (use `scripts/test_4986_payload.php` locally) to identify if mismatches are due to canonicalization differences or producer changes.
	3. If mismatches are widespread (> threshold), open a cross-team incident: collect sample payloads, timestamps, forwarder version, and producer changelog.
	4. If limited to few terminals, contact device owner and request a canary re-run. Defer flip to `enforce` until mismatch rate falls below configured threshold for 72 hours.

10) Performance and bulk limits
- Recommendations:
	- Default max batch size: 200 transactions. If a forwarder sends more than the configured `tsms.ingest.max_batch_size`, return HTTP 413 Payload Too Large (preferred) with a clear retry guidance body. Use HTTP 429 only for transient system overload/backpressure scenarios where the request could succeed later.
	- Request timeout: 30s default; WebApp should stream-accept payloads and perform checksum verification asynchronously where possible.
	- If processing is slow, return 202 Accepted with a processing job id for very large batches (opt-in pattern).

11) Observability thresholds & alerts
- Example thresholds (tune per production telemetry):
	- page if checksum mismatch rate > 1% (per tenant) over 10 minutes
	- warn if mismatch rate > 0.2% over 30 minutes
	- page if ingestion error rate > 0.5% over 5 minutes
	- page if duplicate submissions > 1% over 30 minutes

		- Tenant-size adaptive thresholds: configure alert sensitivity by tenant volume (small tenants: multiply thresholds by 5x to avoid noise; large tenants: tighten thresholds by 0.5x). Store tenant-level alert config in monitoring tool tags/labels and update runbook escalation levels accordingly.

12) Schema / versioning
- Include `payload_schema_version` at submission root. If a consumer sees a higher version it does not support, respond with 426 Upgrade Required or route to a compatibility handler. Maintain a migration compatibility matrix and bump the schema version when canonicalization rules change.

		- Canonical test-vector suite (checked-in)
			- Maintain a small canonical vector suite under `docs/canonical_vectors/` containing representative sample payloads and their canonical checksums. For example:
				- `docs/canonical_vectors/single_tx_variant_a.json` + `single_tx_variant_a.checksum`
				- `docs/canonical_vectors/single_tx_variant_b.json` + `single_tx_variant_b.checksum`
				- `docs/canonical_vectors/bulk_2tx_variant_a.json` + `bulk_2tx_variant_a.checksum`
			- CI must run the producer canonicalization (PHP helper) to generate checksums and assert the consumer's checksum routine computes the same values. Include both historical variants (see next section) so runtime parity across languages is assured.

13) Tests & CI (explicit assertions)
- Integration test assertions (must be implemented in CI):
	- When posting TSMS sample payloads, verify `original_payload` is persisted and `payload_checksum` fields are recorded as provided.
	- In `capture` mode: mismatched checksums are logged and metric `webapp.ingest.checksum.mismatch` increments, but HTTP returns 200 and transactions are stored (or queued) for processing.
	- In `enforce` mode (canary tenant): mismatched checksums return 422 and payload is quarantined.

14) Small clarifications
- Re-statement: the forwarder historically removes the explicit top-level `sc_vat_exempt_sales` convenience field before computing per-transaction checksum in some producer versions — consumers should support an `exclude_top_level_sc_vat_exempt_sales` toggle (default `true`) to preserve compatibility.
- Monetary storage: store monetary-like keys as numeric/decimal in DB (DECIMAL(15,2) / NUMERIC) and use the canonical string representation for checksum computation only — do not rely on float binary representation in the DB for canonicalization.
- Example capture-mode log entry (structured JSON):

```
{
	"ts": "2025-09-27T12:30:00Z",
	"level": "warning",
	"logger": "webapp.ingest",
	"event": "checksum_mismatch",
	"submission_uuid": "1111...",
	"tenant_id": 10,
	"terminal_id": 101,
	"transaction_id": "aaaa...",
	"provided_checksum": "<hex>",
	"computed_checksum": "<hex>",
	"notes": "capture mode - ingest allowed"
}
```

If you want, I can also:
- Add the `payload_schema_version` field to the canonical examples and add a tiny canonicalization helper snippet (PHP) to the doc so implementers can copy/paste and ensure exact checksum reproduction.
- Implement the capture-only verification patch in the WebApp (controller/service/config) and add the CI integration test that posts `scripts/test_4986_payload.php`. 

Historical canonicalization toggles (producer behavior mapping)
- Some producer releases historically excluded the explicit top-level `sc_vat_exempt_sales` convenience field when computing per-transaction checksums. To avoid false positives the canonical test-vector suite must include both variants. Maintain a mapping file `docs/canonical_vectors/producer_version_map.json` that records which producer version (or commit hash) corresponds to which canonical variant (e.g., `exclude_sc_vat_exempt: true|false`). Consumers must run both canonical tests when upgrading to a new producer version and update their supported toggle accordingly.

PII redaction/encryption policy (minimal required policy)
- Required PII fields (default): `customer_name`, `customer_phone`, `customer_email`, `customer_address`, `customer_id_number`.
- Default storage policy: redact these fields in DB-stored `original_payload` and store an encrypted compressed copy in S3 when `tsms.ingest.store_encrypted_payload=true`.
- KMS guidance: use per-environment KMS keys, rotate data keys every 90 days, restrict decrypt permissions to a small ops group, and log key access. Document the key rotation and re-encryption process in runbook.

Quarantine lifecycle and operator actions
- Lifecycle:
	- Quarantined submissions TTL: 30 days by default. After TTL, either auto-archive to cold storage or escalate for manual deletion per compliance.
	- Fields to store in quarantine record: `submission_uuid`, `tenant_id`, `terminal_id`, `s3_key` (if archived), `payload_size_bytes`, `provided_checksums`, `computed_checksums`, `received_at`, `quarantined_at`, `released_by`, `released_at`, `release_reason`, `quarantine_status` (`OPEN`|`RELEASED`|`ARCHIVED`|`DELETED`).
- Operator actions:
	- Only users with `ingest.quarantine.manage` role may release or delete quarantined payloads.
	- Releasing requires an audit entry and optionally a manual re-run of checksum validation after corrective measures.

Retention / archival implementation choice (concrete pattern)
- Recommended: S3 object storage with lifecycle rules + DB metadata pointer (see above). This pattern scales and makes lifecycle policies and audits simpler. If S3 is not available, use DB blob + periodic export to cold storage with equivalent lifecycle semantics.

Alerts tuning applicability
- Note: apply tenant-size multipliers to the thresholds above. Small tenants (low throughput) should have looser alert thresholds to avoid noise; large tenants should have tighter thresholds and shorter windows. Prefer monitoring rules that reference per-tenant transaction rates to scale thresholds automatically.

Concurrency semantics for idempotent success body
- When returning idempotent success due to a duplicate detection, return the standardized JSON shown above under idempotent success response shape so integrators can rely on a deterministic payload.

HTTP status for too-large batches
- Default behavior: reject oversized batches with HTTP 413 Payload Too Large and include `Retry-After` guidance if applicable. Use HTTP 429 only for transient load-shedding/backpressure scenarios where a retry later may succeed.

Implementation snippets (copyable)
These three snippets make the doc directly actionable for implementers: schema examples with `payload_schema_version`, a PHP canonicalization helper, and config + controller/service examples for capture-only verification.

A) Add `payload_schema_version` to the examples

Single and bulk examples should include an explicit schema version so forwarders and consumers can handle evolution deterministically. Example update:

```
{
  "payload_schema_version": "2.0",
  "submission_uuid": "11111111-2222-3333-4444-555555555555",
  ...
}
```

B) PHP canonicalization helper (copy/paste)

Use this helper to reproduce TSMS canonicalization deterministically (place in a shared helper or reuse `app/Services/PayloadChecksumService.php` logic):

```php
// ... small helper - drop into your service layer for checksum reproduction
function canonicalize_for_checksum($data, array $monetaryKeys = ['gross_sales','net_sales','amount','vatable_sales','vat_amount','sc_vat_exempt_sales']) {
	if (is_array($data)) {
		// associative arrays sorted, indexed arrays keep order
		$isAssoc = array_keys($data) !== range(0, count($data) - 1);
		if ($isAssoc) ksort($data);

		foreach ($data as $k => $v) {
			// normalize numbers represented as strings
			if (in_array($k, $monetaryKeys, true)) {
				if ($v === null || $v === '') {
					$data[$k] = null;
				} else {
					// strip grouping and currency symbols then format to fixed 2 decimals
					$s = preg_replace('/[^0-9.\-]/', '', (string)$v);
					$data[$k] = number_format((float)$s, 2, '.', '');
				}
				continue;
			}
			$data[$k] = canonicalize_for_checksum($v, $monetaryKeys);
		}
	}
	return $data;
}

function compute_checksum_for_payload($payload, $monetaryKeys = null) {
	if ($monetaryKeys === null) {
		$monetaryKeys = config('tsms.ingest.monetary_keys', ['gross_sales','net_sales','amount','vatable_sales','vat_amount','sc_vat_exempt_sales']);
	}

	$canonical = canonicalize_for_checksum($payload, $monetaryKeys);
	return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
```

C) Config snippet (add to `config/tsms.php`)

Add monetary keys and ingest settings so both producer and consumer can read the same list:

```php
'ingest' => [
	'checksum_mode' => env('TSMS_INGEST_CHECKSUM_MODE', 'capture'), // off|capture|enforce
	'monetary_keys' => ['gross_sales','net_sales','amount','vatable_sales','vat_amount','sc_vat_exempt_sales'],
	'exclude_top_level_sc_vat_exempt_sales' => env('TSMS_INGEST_EXCLUDE_SC_VAT_EXEMPT', true),
	'retention_days' => (int) env('TSMS_INGEST_RETENTION_DAYS', 90),
],
```

D) Capture-only verification controller/service (sketch)

Controller: call into `TsmsIngestService` and respect `tsms.ingest.checksum_mode`.

```php
// in API controller
$mode = config('tsms.ingest.checksum_mode', 'capture');
$raw = $request->getContent();
$service = app(\App\Services\TsmsIngestService::class);
$result = $service->ingestSubmission($raw);
if ($result['status'] === 'quarantined') {
	return response()->json(['success' => false, 'message' => 'Checksum validation failed', 'errors' => $result['errors']], 422);
}
return response()->json(['success' => true, 'message' => 'Accepted', 'data' => $result['meta']], 200);
```

Service (core logic sketch):

```php
class TsmsIngestService {
	public function ingestSubmission(string $rawJson): array {
		$payload = json_decode($rawJson, true);
		$mode = config('tsms.ingest.checksum_mode', 'capture');

		// compute checksums using helper; remove per-transaction payload_checksum before recomputing
		$service = new \App\Services\PayloadChecksumService();
		$checksumResults = $service->validateSubmissionChecksumsFromRaw($rawJson);

		if (!$checksumResults['valid']) {
			// capture mode: log and continue
			if ($mode === 'capture') {
				foreach ($checksumResults['errors'] as $err) {
					Log::warning('Checksum mismatch (capture)', ['error' => $err, 'submission' => $payload['submission_uuid'] ?? null]);
					metrics()->increment('webapp.ingest.checksum.mismatch');
				}
				// persist payload and return accepted meta
				// ...persist logic...
				return ['status' => 'accepted', 'meta' => ['submission_uuid' => $payload['submission_uuid'] ?? null]];
			}

			// enforce mode: persist quarantine and return quarantined status
			// ...persist quarantine...
			return ['status' => 'quarantined', 'errors' => $checksumResults['errors']];
		}

		// checksums valid: persist submission and transactions normally
		// ...persist logic...
		return ['status' => 'accepted', 'meta' => ['submission_uuid' => $payload['submission_uuid'] ?? null]];
	}
}
```

E) CI / Integration test outline (PHPUnit)

Use the existing `scripts/test_4986_payload.php` to generate canonical payloads; the test should POST to `/api/transactions/bulk` and assert the doc's CI assertions:

```php
public function testCaptureModeLogsMismatchButAccepts() {
	// arrange: ensure config('tsms.ingest.checksum_mode') === 'capture'
	// run script to get sample payload (or load corrected_4986_payload.json)
	$payload = json_decode(file_get_contents(base_path('scripts/corrected_4986_payload.json')), true);

	$response = $this->postJson('/api/transactions/bulk', $payload, ['Authorization' => 'Bearer ' . config('tsms.web_app.auth_token')]);
	$response->assertStatus(200);
	$this->assertDatabaseHas('transaction_submissions', ['submission_uuid' => $payload['submission_uuid']]);
	// assert metric increment or log presence using your telemetry test helpers
}
```

If you'd like, I can implement these code changes (config + service + controller + test) and run the integration test locally. Say "implement now" and I'll apply the code changes and run the test. 

## Footnote — WebApp implementation summary

Minimum actionable items the WebApp must implement to adopt this ingestion policy:

- Add config entries in `config/tsms.php` (example: `tsms.ingest` with `checksum_mode`, `monetary_keys`, `exclude_top_level_sc_vat_exempt_sales`, `retention_days`, and related flags). Expose env toggles for safe rollout.
- Check in a small canonical vector suite under `docs/canonical_vectors/` (single_tx_variant_a/b.json, bulk_2tx_variant_a.json) and a `producer_version_map.json` that records historical canonicalization variants.
- Implement `App\Services\TsmsIngestService` that: parses raw JSON, runs `PayloadChecksumService::validateSubmissionChecksumsFromRaw`, persists `original_payload` and submission metadata, and enforces capture|enforce behavior (capture: log+metric and accept; enforce: quarantine + return 422).
- Wire the service into the ingestion controller (e.g., call the service from `TransactionController::storeOfficial` / process entrypoint) so checksum-mode is respected and the logic is feature-flagged via `config('tsms.ingest.checksum_mode')`.
- Persist submission- and transaction-level checksum fields (`submission_payload_checksum` / `payload_checksum`) and the `original_payload` (compressed or pointer to S3) in `transaction_submissions` / `transactions`. Add a quarantine table or `status` enum value to store quarantined envelopes.
- Add a PHPUnit integration test that posts the checked-in canonical vectors to `/api/transactions/bulk` in `capture` mode and asserts HTTP 200, the submission is recorded, and the mismatch metric/log was emitted. CI should run the PHP helper (or `scripts/test_4986_payload.php`) to regenerate checksums and ensure producer/consumer parity.
- Add basic retention/archival wiring (S3 archival helper + DB metadata) and a simple PII redaction/encryption toggle (`tsms.ingest.store_encrypted_payload`, PII field list and KMS guidance) for archived payloads.
- Add monitoring/alerting hooks (metric names in this doc) and tenant-adaptive thresholds; emit `webapp.ingest.checksum.mismatch` on mismatches so CI and ops can validate behavior.

These minimal changes enable a safe capture-first rollout and provide the CI artifacts needed to assert producer/consumer checksum parity. I can implement these artifacts (configs, canonical vectors, service, controller wiring, and the PHPUnit integration test) and run the test locally on request.
