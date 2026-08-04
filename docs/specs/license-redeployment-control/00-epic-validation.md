# Epic Validation

Document ID: `TSMS-LIC-RDC-VALIDATION`
Status: Reviewed - observe mode approved, enforcement blocked pending authorization package
Last updated: 2026-07-20

## Epic Structure Review

| Required Field | Status | Notes |
|---|---:|---|
| Epic title | Present | `TSMS License Redeployment Control` |
| Business objective | Present | Prevent unauthorized copied, restored, reinstalled, redeployed, or reused TSMS deployments from operating outside approved client/deployment/location. |
| Feature overview | Present | Release 1 is a redeployment-control perimeter, not a full licensing platform. |
| Scope | Present | Signed license verification, deployment fingerprint, middleware, audit logs, binding, POS checks, diagnostic endpoints, recovery. |
| Out of scope | Present | Billing, subscriptions, module packaging UI, advanced entitlements, online license server, polished dashboard. |
| User stories | Present | 13 stories documented. |
| Acceptance criteria | Present | Each story has criteria and definition of done. |
| Technical requirements | Present | Services, middleware, migrations, env vars, route constraints, cryptographic requirements. |
| Dependencies | Partially present | Vendor key management, production license values, role/permission seeding, migration window, and production table sizing need named owners. |
| CI/CD considerations | Present | Focused PHPUnit suite, migrations, observe-mode deployment, release gates. |
| Risks and assumptions | Present | Includes fake vendor account risk, migration risk, accidental enforcement risk, debug route risk. |
| Success metrics | Present | Includes binding completeness, observe violations, endpoint authorization, test pass criteria. |

## Production Authorization Assessment

Release 1 observe-mode development is approved from an architecture perspective. Production `restricted` or `enforce` mode is not approved until the authorization package below is completed and signed off.

Required approval artifacts:

| Artifact | Purpose | Status |
|---|---|---:|
| `LIC-POL-001` License Identity and Environment Policy | Defines canonical client, deployment, license, location, and environment identity formats and issuance authority. | Required |
| `LIC-KMP-001` Vendor Key Management Plan | Defines signing authority, private-key custody, rotation, revocation, audit trail, and public-key distribution. | Required |
| `LIC-TKN-001` Vendor Action Token Profile | Defines signed action-token format, claims, actions, expiry, audience, algorithm allowlist, and validation rules. | Required |
| `LIC-RPL-001` Replay Prevention and Audit Retention Policy | Defines nonce/request-ID storage, atomic consumption, TTL, retention, and replay alerting. | Required |
| `LIC-OPS-001` Restricted Mode and Offline Operations Matrix | Defines what continues and what blocks during restricted mode, vendor outage, missed heartbeat, DR, and recovery. | Required |
| `LIC-MIG-001` Production Migration and Rollback Runbook | Defines production DB profiling, staged migration, chunked backfill, index validation, rollback, and rehearsal evidence. | Required |
| `LIC-RTE-001` Production Route Disposition Register | Confirms actual Laravel route inventory and disposition for debug, legacy, diagnostic, and protected routes. | Required |
| `LIC-ROL-001` Observe-to-Enforce Rollout and RACI | Defines rollout stages, accountable owners, promotion metrics, and go/no-go approval gates. | Required |

## Recommended Rollout Gate

| Stage | Minimum Duration | Required Behavior |
|---|---:|---|
| Development validation | Until automated tests pass | Local/test licenses only. |
| Staging observe mode | 2 weeks | Detect and log; never block. |
| Production observe mode | 30 calendar days | Detect, alert, classify events, measure false positives; never block POS ingestion for license reasons. |
| Restricted pilot | 14 calendar days | Enforce selected internal/admin operations or pilot terminals only. |
| Controlled production enforcement | 14-30 days | Progressive tenant/provider rollout. |
| Full enforcement | After executive gate | All applicable deployments. |

Production observe mode shorter than 30 calendar days requires documented evidence of:

- no unexplained fingerprint changes;
- no false-positive binding violations;
- successful recovery drill;
- successful rollback rehearsal;
- no staging licenses accepted in production;
- no unsigned, replayed, expired, or algorithm-confused vendor action tokens accepted.

## Missing or Critical Information To Resolve

1. Production license policy values are not final and need canonical formats:
   - `LICENSE_CLIENT_ID`
   - `LICENSE_DEPLOYMENT_ID`
   - `LICENSE_ID`
   - `LICENSE_LOCATION_CODE`
   - `LICENSE_ENVIRONMENT`

   Recommended direction:

   - `LICENSE_CLIENT_ID`: opaque vendor-issued client ID, recommended `cli_<uuidv7>`.
   - `LICENSE_DEPLOYMENT_ID`: opaque deployment lifecycle ID, recommended `dep_<uuidv7>`.
   - `LICENSE_ID`: unique signed artifact ID, recommended `lic_<uuidv7>`.
   - `LICENSE_LOCATION_CODE`: controlled business location code, recommended `PH-PITX-MAIN` format using uppercase letters, digits, and hyphens.
   - `LICENSE_ENVIRONMENT`: strict enum such as `development`, `test`, `staging`, `production`, `disaster-recovery`.

2. Staging versus production license identity is not finalized. Staging and production must use separate deployment IDs, license IDs, fingerprints, action-token audiences, secret stores, and preferably separate signing keys.

3. Vendor key-management process is not specified:
   - signing authority
   - private key custody
   - public key rotation
   - emergency key revocation
   - key audit trail
   - public-key distribution
   - incident communication path

   The TSMS application must never contain the vendor private signing key.

4. Vendor-signed action token format is not yet formally defined. The token profile must specify:
   - protected header, including `typ`, `alg`, and `kid`;
   - required claims, including issuer, audience, `jti`, request ID, action, client ID, deployment ID, license ID, environment, location code, fingerprint claims, approver identity, `iat`, `nbf`, `exp`, nonce, and schema version;
   - permitted action enum;
   - maximum token lifetime per action;
   - strict algorithm allowlist and audience validation.

5. Replay-protection storage for vendor action nonces/request IDs is not yet defined. The required design is an atomic database-backed consumption ledger, with Redis/cache used only as an optimization. Observe mode must not accept replayed tokens.

6. Heartbeat and terminal authentication behavior during restricted mode remains undecided. The policy must separate:
   - local deployment license validity;
   - POS terminal authentication;
   - optional vendor heartbeat/telemetry.

   A vendor connectivity outage must not automatically invalidate a valid locally signed license or valid terminal credentials.

7. Production migration impact is unknown for large tables, especially `transactions.deployment_id` indexing. A phased expand-backfill-index-validate migration and production-scale rehearsal are required before enforcement.

8. Legacy/debug/test route disposition is unresolved before restricted/enforce mode. The actual Laravel route inventory must be generated and reviewed before production observe, then fully dispositioned before restricted pilot.

9. Observe-mode duration and approval gate for restricted/enforce mode are not assigned to a specific release owner. A named release manager, vendor license release authority, PITX IT owner, security owner, and business approvers are required.

10. Historical deployment attribution policy is not defined. Existing transactions must not be blindly assigned to the current production deployment if historical provenance is uncertain.

11. Support ownership after contract expiry is not defined. Recovery and licensing operations may require a support/SLA agreement or change order.

## Production Enforcement Promotion Gates

| Gate | Required Evidence | Approver |
|---|---|---|
| Architecture gate | Final identity, token, replay, heartbeat/offline, and restricted-mode specifications. | Solution Architect |
| Security gate | Key-management plan, negative token tests, replay tests, route cleanup, and secret-redaction tests. | Security Owner |
| Data gate | Production profiling, migration rehearsal, backfill result, index plan, historical attribution policy, rollback rehearsal. | DBA/DevOps |
| Operational gate | Runbook, support contacts, monitoring/alerting, recovery drill, emergency rollback procedure. | IT Operations |
| Business gate | Finance/POS impact of restricted mode accepted. | Finance Head and PITX IT Head |
| Contract gate | Vendor/client support and recovery responsibilities confirmed. | Vendor Management / Client |
| Production go/no-go | All evidence signed and release window approved. | Named Release Authority |

Minimum metrics before restricted mode:

```text
100% production transactions receive expected deployment identity for new writes
100% protected routes have explicit authorization tests
0 unexplained critical fingerprint changes for 14 consecutive days
0 known replay-protection bypasses
0 staging licenses accepted in production
0 unsigned or algorithm-confused tokens accepted
100% recovery drill success
100% key-rotation verification tests passed
all critical observe events classified
```

Minimum metrics before full enforcement:

```text
No unresolved Severity 1 or Severity 2 licensing defect
False-positive binding rate effectively zero for 14 consecutive days
Pilot terminals complete normal operational cycles
Vendor-unavailability test succeeds
Rollback executed successfully in rehearsal
Client IT and Finance sign operational acceptance
```

## Validation Result

The Epic is structurally sound and implementation-ready for Release 1 observe-mode development.

Production restricted/enforce mode remains blocked until all eight authorization artifacts are approved, production database rehearsal is successful, route cleanup is complete, key custody is operational, recovery and rollback drills pass, and PITX IT, Finance, the Vendor Release Authority, and the named Release Manager sign the promotion gate.

Final architectural position:

```text
A valid locally signed license permits continued transaction operations.
Vendor authorization is required only for security-sensitive state changes such as activation, rebinding, recovery, replacement, reinstall, and redeployment.
```
