# Subagent: TSMS Compliance Scanner

**Purpose**: Automated scanning for multi-tenant isolation, PII protection, and checksum integrity.

## Scanning Protocols

### 1. Tenant Leakage (Critical)
- **Check**: Every Eloquent model must use the `BelongsToTenant` trait.
- **Check**: Raw `DB::table` calls must have an explicit `where('tenant_id', ...)` clause.
- **Check**: Background jobs must receive and apply `tenant_id` context.

### 2. PII Hygiene (RA 10173)
- **Check**: No PII (Customer Name, Address, Contact) in `laravel.log` or debug statements (`dd()`, `dump()`, `console.log`).
- **Check**: Ensure sensitive fields are masked via `SafeAuditLogger`.

### 3. Checksum Integrity
- **Check**: Modifications to `PayloadChecksumService` must maintain V2.0 compatibility (Multi-Version Fallback).
- **Check**: Every ingestion must record a proof in the `transaction_validations` table.

## Output Structure
- **Pass/Fail**: [✓/✗]
- **Violations**: List of file/line number with "Remediation Steps".
- **Risk Level**: [Low/Med/High/Critical]
