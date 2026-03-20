# TSMS Security Skill

---
name: security
description: Enforce multi-tenant isolation and PII hygiene
argument-hint: isolation|pii|hygiene
---

# Security Skill

## Goals
- Prevent cross-tenant data leakage (Absolute Rule).
- Ensure 0% PII leakage to logs (Security Hygiene).
- Audit RBAC permission changes.

## Actions
| Action | Description |
|--------|-------------|
| `isolation` | Run the `compliance-scanner` for tenant_id leaks in raw queries. |
| `pii` | Scan `storage/logs/` and code for denylisted patterns (card_no, tin). |
| `hygiene` | Review middleware and database migrations for security best practices. |

## Reference Files
- [security-hygiene-guardrails.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/docs/security/security-hygiene-guardrails.md)
- [engineering-safeguards-policy.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/docs/standards/engineering-safeguards-policy.md)
- [BelongsToTenant.php](file:///Users/teamsolo/Projects/PITX/tsms-dev/app/Traits/BelongsToTenant.php)
