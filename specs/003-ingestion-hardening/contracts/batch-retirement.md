# Contract: Batch endpoint retirement

`POST /api/v1/transactions/batch` (authenticated, `transaction:create` ability)

| Case | Status | Body |
| --- | --- | --- |
| Authenticated, any body | 410 | `{"success": false, "error_code": "ENDPOINT_RETIRED", "message": "The batch endpoint has been retired. Submit transactions to /api/v1/transactions/official.", "replacement": "/api/v1/transactions/official"}` |
| No token | 401 | unchanged Sanctum JSON |
| Token without `transaction:create` | 403 | unchanged |

Side effects: none (no transaction, intake, job, SystemLog). One `Log::warning('batch endpoint retired: request refused', {terminal_id, tenant_id, correlation_id})`.

When `TSMS_ENABLE_LEGACY_API=true`, the legacy `v1/transactions/batch` route returns the same 410 body.
