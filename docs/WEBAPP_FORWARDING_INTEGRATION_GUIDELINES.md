# Webapp Forwarding Integration Guidelines

Purpose
-------
This document explains how the downstream Web Application ("webapp") should integrate with the updated TSMS forwarding contract. TSMS now supports an "accept-with-issues" remediation path for checksum mismatches and annotates forwarded envelopes with a `with_issues` flag. The goal is to: 

- Ensure the webapp can correctly receive and display transactions that were accepted despite checksum validation problems.
- Preserve idempotency and auditability.
- Provide clear UI/UX and operational guidance for triage and reconciliation teams.

Audience
--------
Webapp backend and frontend engineers, data engineers who consume forwarded transactions, and SRE/ops teams responsible for alerting and monitoring.

High-level changes to expect
----------------------------
1. Envelope-level flag: `with_issues` (boolean)
   - Appears on the bulk forwarding envelope. If `true`, at least one transaction in the batch had checksum/validation issues.
2. Per-transaction field: `validation_status`
   - Values: `VALID`, `WITH_ISSUES`, `PENDING`, `FAILED`, etc. Transactions accepted under the new flow will have `validation_status: "WITH_ISSUES"`.
3. Envelopes and transaction payloads include the same checksum/batch checksum fields as before. The checksum contract is unchanged. `with_issues` is purely a metadata signal and does not alter how checksums are computed.

Sample envelope (illustrative)
------------------------------
{
  "source": "TSMS",
  "schema_version": "2.0",
  "batch_id": "TSMS_20251114...",
  "timestamp": "2025-11-14T12:34:56.123Z",
  "tenant_id": 123,
  "terminal_id": 456,
  "transaction_count": 2,
  "with_issues": true,         // NEW: indicates batch contains transactions with issues
  "batch_checksum": "...",
  "transactions": [
    {
      "transaction_id": "...",
      "validation_status": "WITH_ISSUES", // NEW: per-transaction status
      "checksum": "...",
      ...
    }
  ]
}

Contract and validation rules for the webapp
--------------------------------------------
- Accept `with_issues` as an optional boolean on incoming envelopes. Default to `false` if missing.
- Do NOT treat `with_issues: true` as a syntactic failure. It is an operational signal meaning: "TSMS accepted these rows for POS continuity but flagged them for triage." 
- Still validate the batch checksum and per-transaction checksum according to the existing contract. The presence of `with_issues` does not change checksum semantics — the checksum values remain authoritative and must be stored with the received payload for audit.
- Use `submission_uuid` and `batch_id` to deduplicate and ensure idempotent processing. TSMS will attempt to avoid duplicate forwards when possible, but your pipeline should be resilient.

Idempotency and deduplication
-----------------------------
- Primary keys for deduplication: `batch_id` (envelope), `submission_uuid` (submission), and per-transaction `transaction_id`. Use a composite or prioritized check: prefer `transaction_id` uniqueness first, then `submission_uuid/batch_id` for group-level idempotency.
- If you receive a batch where `batch_id` or `submission_uuid` was processed previously, treat it as idempotent — accept but skip duplicate writes.

Storage recommendations
-----------------------
- Persist the `with_issues` flag at the envelope level and the `validation_status` per transaction.
- Keep original forwarded `request_payload` and `checksum` values in a raw audit table for forensic reconciliation. Store `ingested_at` and the HTTP response or metadata returned by the forwarding pipeline.

UI/UX recommendations
---------------------
- Surface an unobtrusive banner or badge on transactions with `validation_status == 'WITH_ISSUES'` and/or when the batch's `with_issues` is true.
  - Example: a yellow "Issue" badge with hover that explains: "TSMS accepted this transaction despite a checksum mismatch; please review." 
- Provide filter/view for "Accepted with issues" transactions so operations teams can triage and repair.
- Add a timeline/audit view that links to the raw forwarded payload and the TSMS quarantine id (if available) for forensic analysis.

Automated processing guidance
-----------------------------
- For downstream automated systems (reporting, accounting, invoicing): by default, exclude `WITH_ISSUES` transactions from high-assurance workflows until a human verifies them or a reconciliation job marks them as trusted.
- Provide a reconciliation endpoint or workflow to allow ops to mark specific WITH_ISSUES transactions as "trusted" (promo, rounding, or minor metadata mismatch) — that marking should re-run downstream pipelines.

Alerting & monitoring
---------------------
- Emit metrics when you receive `with_issues === true` envelopes:
  - `webapp.forwarding.received_with_issues.count` (per-tenant)
  - Alert when rate exceeds a configured threshold (e.g., 5% of transactions or > N per minute) to catch systemic issues.
- Track counts of `WITH_ISSUES` by tenant and terminal. Use dashboards to display spikes.

Processing policy (recommended default)
--------------------------------------
1. If `webapp.forward_with_issues` is disabled (TSMS config) you should never receive `WITH_ISSUES` transactions; treat received WITH_ISSUES as unexpected — log and alert.
2. If `with_issues` is present and `validation_status == 'WITH_ISSUES'`:
   - Persist for triage (do not automatically trust for financial posting unless a reconciliation step marks them as trusted).
   - Show the transaction in ops UI with clear triage actions (investigate, mark trusted, request re-submit).

Integration testing checklist
-----------------------------
- Test that the webapp accepts envelopes with `with_issues: true` and that your pipeline does not throw schema errors.
- Test idempotency by re-sending the same `batch_id` and `submission_uuid` and verifying no duplicate records are created.
- Test a complete acceptance flow with `validation_status == WITH_ISSUES` and then record the reconciliation step that marks it trusted; verify downstream pipelines pick it up.
- Test the UI: filters, badge, and the raw payload link work.

Security and privacy
--------------------
- Treat raw payloads and quarantined payloads as sensitive data. Ensure any UI that exposes full payloads is permissioned to authorized roles only.
- Avoid exposing internal TSMS stack traces or checksum internals to general users.

Operational runbook (quick)
---------------------------
1. Spike in `with_issues` rate:
   - Inspect `webapp.forwarding.received_with_issues.count` and tenant-level rates.
   - Pull recent raw envelopes via admin tools and compare checksums to local recompute.
   - If systemic, contact TSMS ops and consider toggling `TSMS_INGESTION_MODE` (back to QUARANTINE) while investigating.
2. False positive acceptance (transaction actually invalid):
   - Use reconciliation UI to mark as rejected; reverse downstream postings if already processed.

Developer notes / code snippets
------------------------------
Example: graceful ingestion endpoint pseudo-code

1) Validate envelope schema (allow `with_issues` boolean):
- If `with_issues` present, log and attach telemetry.
2) For each transaction:
- Store `validation_status` and `transaction_id`.
- Use `transaction_id` + `batch_id` dedupe to avoid duplicates.

Rollout guidance
-----------------
- Start with `TSMS_INGESTION_MODE=QUARANTINE` in TSMS and validate webapp can process normal envelopes.
- Enable `ACCEPT_WITH_ISSUES` for a small pilot tenant; set `WEBAPP_FORWARD_WITH_ISSUES=true` in staging to test forwarding behavior.
- After verification, enable per-tenant opt-in as required.

Appendix: Questions & clarifications
----------------------------------
- Q: Does `with_issues` imply data is unreliable? A: It indicates a checksum mismatch was detected by TSMS. The data was accepted to avoid POS impact, but it should be treated as requiring triage before high-assurance use.
- Q: Should the webapp recompute checksum? A: You may recompute checksums for verification and store both values; however, reconciliation should rely on TSMS quarantines and audit events for root-cause.

If you'd like, I can also:
- Produce a small JSON schema snippet for validation middleware.
- Add a sample UI mockup for the triage workflow.
- Create feature tests for envelope handling.

Document version: 2025-11-14
