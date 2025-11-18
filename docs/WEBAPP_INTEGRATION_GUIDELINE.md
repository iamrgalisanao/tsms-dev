# Webapp Integration — Implementation Guideline

Purpose
-------
This document is a concise reference for Webapp engineers integrating with the TSMS read-only Webapp API. It explains the available endpoints, authentication model, expected responses, rate limiting and caching behavior, recommended integration steps, and troubleshooting tips.

Base URL
--------
All endpoints are under the API prefix:

https://<tsms-host>/api/v1/webapp

Primary endpoints
-----------------
- GET /transactions
  - Description: Paginated list of transactions. Supports filters and pagination.
  - Query params (commonly used): page, per_page, tenant_id, terminal_id, from, to, status
  - Response: paginated JSON collection of TransactionResource objects (data + meta)

- GET /transactions/count
  - Description: Returns a server-authoritative count for matching transactions.
  - Query params: same filter set as /transactions. Use the same filters to keep UI counts consistent with list results.
  - Response: { "count": <integer> }
  - Notes: Count results are cached (token-aware) for `WEBAPP_API_CACHE_TTL` seconds to reduce DB load.

- GET /transactions/{id}
  - Description: Returns a single transaction payload for read-only display.
  - Response: TransactionResource JSON

Authentication & Authorization
------------------------------
1. Preferred: Laravel Sanctum personal access tokens with ability `webapp:read`
   - Token creation (TSMS server side):

     php artisan webapp:token create --email=service-webapp@yourdomain --name=webapp-machine-token --abilities=webapp:read

   - Use the returned plain-text token as a Bearer token in Authorization header.

2. Short-term staging fallback: static bearer token(s)
  - Controlled by `WEBAPP_API_USE_STATIC_TOKENS` in TSMS config. This is intended for short-lived staging/testing only.
  - TSMS defaults to static tokens disabled. Do NOT enable static tokens in production. Store machine tokens in a secrets manager (Vault, AWS Secrets Manager, or your CI provider's secret store) and inject them into the runtime environment or CI pipelines instead.

3. IP allowlist
   - TSMS enforces `WEBAPP_API_ALLOWED_IPS` to reduce blast radius. If your requests are proxied through a load balancer, ensure `X-Forwarded-For` is passed and trusted by TSMS.

Headers
-------
Include the Authorization header with Bearer token:

Authorization: Bearer <plain-token>

Client integration examples
---------------------------
- Count (curl):

  curl -s -H "Authorization: Bearer <plain-token>" \
    "https://stagingtsms.pitx.com.test/api/v1/webapp/transactions/count?tenant_id=123"

- Index (paginated):

  curl -s -H "Authorization: Bearer <plain-token>" \
    "https://stagingtsms.pitx.com.test/api/v1/webapp/transactions?page=1&per_page=50&tenant_id=123"

- Show (single):

  curl -s -H "Authorization: Bearer <plain-token>" \
    "https://stagingtsms.pitx.com.test/api/v1/webapp/transactions/98765"

Error handling
--------------
- 401 Unauthorized: missing or invalid token.
- 403 Forbidden: source IP not in allowlist or token lacks required ability.
- 429 Too Many Requests: rate limit exceeded (named limiter `webapp`). Back off and retry.
- 503 Service Unavailable: Webapp API disabled via config toggle.

Rate limiting & caching
-----------------------
- Rate limiter: TSMS applies a named `webapp` limiter to these routes. Expect standard HTTP 429 responses when exceeded.
- Count endpoint caching: results are cached per-token to avoid cross-client cache leakage. Cache TTL is controlled by `WEBAPP_API_CACHE_TTL` (seconds).

Best practices for front-end UX
------------------------------
- Use the `count` endpoint for badges/counters but tolerate a small TTL-based delay for freshness. If you need immediate accuracy after a write, trigger a manual refresh flow.
- When rendering lists, use the same filters for both `/transactions` and `/transactions/count` to avoid mismatch.
- Implement exponential backoff for 429 responses.

Security & operational notes
---------------------------
- Use a dedicated service account (email `service-webapp@...`) and restrict token abilities to `webapp:read` only.
- Store tokens in a secrets manager (do not commit them to source control or embed in client JS).
- Rotate tokens periodically and have an emergency revocation procedure.

Testing & verification
----------------------
- Use the `webapp:token` Artisan command to create tokens for integration tests. For CI/staging, store the token in CI secrets and run a lightweight smoke test that calls `/transactions/count`.
- Note: TSMS schedules the `reporting:refresh` command daily to keep summary tables updated. If your tests rely on summary tables, either run `php artisan reporting:refresh` in your test setup or ensure the scheduler has run.
- Add tests for allowlist behavior if your client is behind a proxy; verify `X-Forwarded-For` is preserved.

Troubleshooting
---------------
- If you receive 401: confirm the token is valid and has `webapp:read` ability. Use `php artisan webapp:token list --email=service-webapp@...` on TSMS to inspect tokens.
- If you receive 403: check `WEBAPP_API_ALLOWED_IPS` and confirm the request IP matches (consider proxies).
- If counts appear stale: check `WEBAPP_API_CACHE_TTL` in TSMS config. For immediate consistency, request the list endpoint and derive counts client-side.

Contact & support
-----------------
For rollout or emergency issues, contact the TSMS ops/admin email in `.env` (`ADMIN_EMAIL`) or open an incident in the team's channel.

Glossary
--------
- TSMS: Transactional System for Metro (this repository)
- Webapp: the external web UI consuming TSMS read-only API
- Token: Sanctum personal access token (preferred) or static bearer token (staging-only)
