# TSMS Ingestion Skill

---
name: ingestion
description: Manage transaction ingestion logic - checksums, sharding, and payload validation
argument-hint: checksum|sharding|simulate
---

# Ingestion Skill

## Goals
- Maintain cryptographic integrity of POS payloads (V2.0 & V2.1).
- Ensure deterministic sharding across Redis queues (tenant_id % 8).
- Validate payload structure against the official spec.

## Actions
| Action | Description |
|--------|-------------|
| `checksum` | Run manual verification of a payload checksum (V2.0/V2.1 fallback). |
| `sharding` | Verify the shard assignment for a given `tenant_id`. |
| `simulate` | Generate a valid V2.1 JSON payload for testing. |

## Reference Files
- [PayloadChecksumService.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/app/Services/PayloadChecksumService.php)
- [TransactionIngestService.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/app/Services/TransactionIngestService.php)
- [spec.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/spec.md)

## Logic Invariants
- **Checksum**: V2.1 uses strict string formatting; V2.0 uses numeric rounding.
- **Sharding**: Mandatory `shard = tenant_id % 8`. Queue name: `transaction-processing:s[0-7]`.
