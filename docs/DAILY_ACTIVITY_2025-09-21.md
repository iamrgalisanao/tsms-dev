# Daily Activity Digest - 2025-09-21
Generated: 2025-09-21T03:10:16Z
Window Since (human): 2025-09-20
Window Spec Used: 24 hours ago

## Changed Files (24h)
(None)

## 24h Diff Stats (Aggregated Insertions/Deletions)
(None)

## Commits (24h window spec)
- c4874cf chore(logs): remove custom search bars; rely on DataTables default search (Log Viewer + Dashboard) (Rommel Galisanao)
- 7032cff chore(logs): remove Advanced Filters UI and server params; keep simple search only on Audit/System views (Rommel Galisanao)
- c67aa37 fix(dashboard-logs): make applyFilters resilient to missing fields to prevent null.value TypeError from Advanced Filters partial (Rommel Galisanao)
- 9702538 feat(log-viewer): enable Advanced Filters on Audit Trail tab (AJAX endpoint + view wiring + DataTable reinit) and show filters on dashboard logs (Rommel Galisanao)
- 9e7addc feat(audit-filters): add Tenant filter (by tenants.trade_name) and server-side tenant_id filtering (Rommel Galisanao)
- 387e7be feat(audit): populate Tenant column using tenants.trade_name; decode legacy metadata; include tenant in audit modal (Rommel Galisanao)
- 1c24bc6 fix tenant_name to trade_name (Rommel Galisanao)
- 7ec63aa include trade_name or tenant name on the audit trail table (Rommel Galisanao)
- 479f8e9 feat(idle-monitor): add last_sale_at and configurable activity_basis (last_seen|last_sale|composite); optional currently idle list in summaries; preserve defaults (Rommel Galisanao)

## Files From Listed Commits
(None)

## Uncommitted Working Tree Changes
Legend: M=Modified, A=Added, D=Deleted, R=Renamed, ??=Untracked
- [M ] pp/Http/Controllers/DashboardController.php
- [M ] pp/Notifications/TransactionFailureThresholdExceeded.php
- [M ] emAgent/.env.example
- [M ] emAgent/cipher-minimal.yml
- [M ] ps/horizon.service.example
- [M ] esources/views/dashboard.blade.php
- [M ] esources/views/dashboard/index.blade.php
- [M ] esources/views/logs/partials/audit-table.blade.php
- [M ] outes/web.php
- [M ] cripts/tsms-cipher-memory.sh
- [M ] ests/Feature/LogViewerTest.php
- [M ] ests/Feature/Module2Test.php
- [M ] ests/Feature/WebAppForwardingSchemaV2Test.php
- [M ] ests/Scripts/RunModule2Tests.php
- [M ] ests/Scripts/TestTextFormatParser.php
- [??] CIPHER_CONFIGURATION_GUIDE.md
- [??] CIPHER_INTEGRATION_SETUP.md
- [??] WEBAPP_FORWARDING_GUIDELINES.md
- [??] _md/PER_TENANT_CIRCUIT_BREAKER_PLAN.md
- [??] _md/TSMS_POS_Transaction_Payload_Guidelines_v2.md
- [??] _md/UAT_Role_Based_Test_Plans.md
- [??] corrected_4986_payload.json
- [??] docs/CIPHER_WORKFLOW.md
- [??] docs/DAILY_ACTIVITY_2025-09-13.md
- [??] docs/DAILY_ACTIVITY_2025-09-15.md
- [??] docs/DAILY_ACTIVITY_2025-09-19.md
- [??] docs/DAILY_ACTIVITY_2025-09-21.md
- [??] docs/PRODUCTION_DEPLOYMENT_AND_LICENSE.md
- [??] "docs/PTX. FIN. ACT. Certified Monthly Sales Report Template (Vat Registered). PRC. 2025 02 21 1.xlsx"
- [??] memAgent/START_CIPHER.md
- [??] memAgent/cipher-final.yml
- [??] memAgent/cipher-minimal-schema.yml
- [??] memAgent/cipher-providers-only.yml
- [??] memAgent/cipher-test.yml
- [??] scripts/cipher-generate-daily-activity.sh
- [??] scripts/cipher-pre-dev.sh
- [??] scripts/cipher-refresh-changes.sh
- [??] scripts/cipher-search.sh
- [??] scripts/cipher-warm-all.sh
- [??] scripts/deploy-staging.sh
- [??] scripts/run-cipher-final.sh
- [??] scripts/verify-cipher-bmad.sh
- [??] tests/Feature/API/V1/OfficialSubmissionTest.php
- [??] tests/Feature/DashboardNotificationTest.php
- [??] tests/Feature/FutureTimestampToleranceTest.php
- [??] tests/Feature/SubmissionIdempotencyTest.php
- [??] tests/Feature/TransactionSubmissionTest.php
- [??] tests/Feature/WebAppForwardingBatchCaptureTest.php
- [??] tests/Feature/WebAppForwardingCircuitBreakerTest.php
- [??] tests/Feature/WebAppForwardingNegativeValidationTest.php
- [??] tests/Feature/WebAppForwardingServiceTest.php
- [??] tests/Unit/TenantBreakerObserverTest.php
- [??] tests/Unit/TransactionNetAmountTest.php
- [??] tmp/

## Notes
Auto-generated. Use to seed memory ingestion (resides in docs/). Set SKIP_CIPHER_REFRESH=1 to skip auto refresh.
