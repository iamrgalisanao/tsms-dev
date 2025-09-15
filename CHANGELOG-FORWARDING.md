# Forwarding Schema v2.0 Deployment Notes

Date: 2025-09-15

## Summary
Introduces unified bulk forwarding envelope (schema_version 2.0) for both batch and single transaction forwarding, adding explicit tenant_id and terminal_id at the root plus a deterministic batch checksum.

## Key Features
- Root fields: tenant_id, terminal_id, transaction_count, batch_checksum
- Homogeneity enforcement: mixed tenant / terminal batches rejected (LOCAL_BATCH_CONTRACT_FAILED)
- Batch checksum: sha256(schema_version|source|batch_id|tenant_id|terminal_id|count|sorted(tx checksums))
- Capture-only test mode (config: tsms.testing.capture_only)
- Feature flag gating (config: tsms.web_app.enabled)
- Local validation & contract failures excluded from circuit breaker increments

## Hardening Added
- Structured logging of validation failures including missing_ids metric
- Dry-run console command: `php artisan tsms:forwarding-dry-run {transaction_id?} [--immediate] [--json]`

## Rollout Plan (Staging)
1. Deploy with WEBAPP_FORWARDING_ENABLED=false
2. Run dry-run command to inspect envelope
3. Toggle enabled true with small batch size
4. Monitor logs for validation or network classifications
5. Scale batch size to normal

## Rollback
Set WEBAPP_FORWARDING_ENABLED=false and (optionally) clear circuit breaker cache keys:
- webapp_forwarding_circuit_breaker_failures
- webapp_forwarding_circuit_breaker_last_failure

## Observability
Search logs for: `Outbound payload validation failed` and inspect `missing_id_metric` field.
