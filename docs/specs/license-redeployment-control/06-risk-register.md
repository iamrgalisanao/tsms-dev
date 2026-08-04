# Risk Register

Document ID: `TSMS-LIC-RDC-RISK`
Status: Draft
Last updated: 2026-07-20

| ID | Risk | Impact | Likelihood | Mitigation | Owner |
|---|---|---:|---:|---|---|
| R-001 | Client admin creates fake vendor role/user locally. | High | High | Require vendor-signed action token for license-changing operations. | Engineering / Vendor Security |
| R-002 | Production values are guessed or incorrect. | High | Medium | Confirm values from vendor/business deployment records before backfill/enforcement. | Product / Vendor Ops |
| R-003 | Missing migrations break observe-mode audit logging. | High | Medium | Run migrations before traffic reaches observe-mode protected routes. | DevOps |
| R-004 | Accidental restricted/enforce mode blocks POS intake. | Critical | Medium | Explicitly set `LICENSE_ENFORCEMENT_MODE=observe`; restrict env changes; document rollback to `disabled`. | DevOps |
| R-005 | Large `transactions` table migration causes locks or slowdown. | High | Medium | Assess table size/index strategy and schedule maintenance window. | DBA / DevOps |
| R-006 | Debug/test/legacy routes bypass licensing perimeter. | Medium | Medium | Remove, disable, or protect before restricted/enforce. | Engineering |
| R-007 | Unstable fingerprint signals cause false lockouts. | High | Medium | Keep IP/hostname/MAC/cloud instance data as diagnostics only by default. | Engineering |
| R-008 | Vendor private signing key leaks into TSMS runtime. | Critical | Low | Store only public verification key in TSMS; vendor key custody process required. | Vendor Security |
| R-009 | Audit logs expose secrets. | High | Low | Sanitize logs and test for tokens, signatures, env values, and raw fingerprint data. | Engineering / QA |
| R-010 | Recovery flow enables self-reactivation. | High | Medium | Require manual vendor approval and signed recovery license/action token. | Vendor Ops |

## Assumptions

- Vendor controls license issuance and private signing keys.
- TSMS runtime only has public verification keys.
- Client admins may manage TSMS operations but must not authorize licensing, redeployment, reinstall, or reuse.
- Release 1 excludes billing, subscriptions, module packaging UI, online license server, and advanced entitlements.
- Production enforcement proceeds gradually: `disabled -> observe -> restricted -> enforce`.

