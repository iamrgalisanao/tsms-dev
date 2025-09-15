# Project Name

A brief description of what this project does and who it's for.

## Table of Contents
- [Installation](#installation)
- [Usage](#usage)
- [Development](#development)
- [Contributing](#contributing)
- [License](#license)

## Installation
Describe how to install and set up the project. Include prerequisites, dependencies, and configuration steps.

## Usage
Provide instructions and examples for using the application.

## Development
- Directory structure overview
- How to run tests
- How to build or deploy

### Bulk Forwarding Schema v2.0
All real-time (single) and batch transaction forwards now use a unified envelope.

Envelope (schema_version = 2.0):
```
{
	"source": "TSMS",
	"schema_version": "2.0",
	"batch_id": "TSMS_YYYYMMDDHHMMSS_<uniqid>",
	"timestamp": "2025-09-15T23:10:45.123Z",
	"tenant_id": <int>,
	"terminal_id": <int>,
	"transaction_count": <int>,
	"batch_checksum": <64 hex sha256>,
	"transactions": [
		{
			"tsms_id": <int>,
			"transaction_id": "UUID or POS ID",
			"terminal_serial": "POS-XXXX",
			"tenant_code": "TENANT-CODE",
			"tenant_name": "Tenant Display Name",
			"transaction_timestamp": "2025-09-15T23:10:45.123Z",
			"amount": 100.00,
			"net_amount": 88.00,
			"validation_status": "VALID",
			"processed_at": "2025-09-15T23:10:46.005Z",
			"submission_uuid": "UUID",
			"adjustments": [ {"adjustment_type": "promo_discount", "amount": 0.00}, ... all required types ... ],
			"taxes": [ {"tax_type": "VAT", "amount": 0.00}, ... required types ... ],
			"checksum": "<64 hex sha256 of normalized transaction payload>"
		}
	]
}
```

Deterministic Batch Checksum:
```
sha256(
	schema_version + '|' + source + '|' + batch_id + '|' + tenant_id + '|' + terminal_id + '|' + transaction_count + '|' + sorted(transaction.checksum list joined by ',')
)
```

Homogeneity Enforcement:
- A batch must contain only one (tenant_id, terminal_id) pair. Mixed batches are rejected with classification LOCAL_BATCH_CONTRACT_FAILED and do NOT increment the circuit breaker.

Local Validation Failures:
- Classification LOCAL_VALIDATION_FAILED; do not affect breaker metrics.

Circuit Breaker:
- Only network / retryable (5xx, DNS) classifications increment failure count.

### Capture-Only Test Mode
To facilitate deterministic test assertions without performing external HTTP calls, set:
```
config(['tsms.testing.capture_only' => true]);
```
Behavior:
- Immediate forwarding returns array containing `captured_payload` (the full v2 envelope) and persists a completed `WebappTransactionForward` row.
- Production/runtime environments must NOT enable this flag.

### Running Targeted Schema Tests
```
php artisan test --filter=WebAppForwardingSchemaV2Test
```
Or individual methods with `--filter=single`, `--filter=batch`, `--filter=homogeneity`.

### Adding Future Schema Versions
1. Introduce new constant (e.g. BULK_SCHEMA_VERSION = '2.1').
2. Add backward-compatible handling on consumer side first.
3. Expand validation rule `'schema_version' => ['in:2.0,2.1']` only after consumers deployed.
4. Update README and integration docs.
5. Consider dual-emitting (old + new) if external consumers need migration window.

### Rollback Considerations
If a regression is found:
1. Disable forwarding via feature flag (future enhancement) OR
2. Revert to previous commit (schema_version 2.0 envelope still accepted).
3. Clear circuit breaker cache keys: `webapp_forwarding_circuit_breaker_failures`, `webapp_forwarding_circuit_breaker_last_failure`.


### Horizon (Queue Monitoring & Workers)
Horizon supervises Redis queues and provides a dashboard for transaction processing throughput & failures.

Access:
- Path: `/command-center` (HORIZON_PATH)
- Protected by `viewHorizon` gate (roles: admin or ops)

Queues Used:
- `transaction-processing` – critical transaction validation / processing
- `forwarding` – webapp forwarding & external callbacks
- `low` – housekeeping (pruning, watchdog, bulk generation, retries)

Environment Variables (see `.env`):
```
HZ_HIGH_PROCESSES, HZ_FORWARD_PROCESSES, HZ_LOW_PROCESSES
HORIZON_TRIM_RECENT, HORIZON_TRIM_COMPLETED, HORIZON_TRIM_RECENT_FAILED, HORIZON_TRIM_FAILED
HORIZON_PREFIX
```

Deployment (systemd example):
```
[Unit]
Description=TSMS Horizon
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/tsms/artisan horizon
ExecStop=/usr/bin/php /var/www/tsms/artisan horizon:terminate
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Rolling Deploy:
1. Pull code & install dependencies
2. Run migrations
3. `php artisan horizon:terminate` (workers restart automatically)

Monitoring Guidance:
- Watch `Wait` > 2s on `transaction-processing` → scale processes
- Investigate spikes in `failed` tagged jobs (e.g. `transaction:pk=...`)
- Adjust process counts via env vars; no redeploy needed

Job Tagging:
- Critical jobs expose tags (transaction:pk, domain:forwarding, domain:bulk-generation, domain:retry) for filtering.

Security:
- Only admin / ops roles pass `viewHorizon` gate.
- Consider IP allowlist or VPN for production access.

Retention:
- Completed jobs trimmed after 120 minutes (adjust via env)
- Failed jobs retained 30 days for audit / post-mortem.

Back Pressure Strategy:
- If queue depth grows & wait increases, temporarily throttle submission endpoints or increase `HZ_HIGH_PROCESSES`.


## Contributing
Guidelines for contributing to the project.

## License
Specify the license for the project.
