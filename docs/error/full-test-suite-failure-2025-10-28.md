# Full test suite failure notes — 2025-10-28

This document captures the problems observed while running the full PHPUnit test suite locally on 2025-10-28. It is intended as a reproducible troubleshooting record for future reference.

Summary
-------
- Command run: `APP_DEBUG=1 php artisan test` (attempted with increased CLI memory)
- Initial symptom: PHPUnit run terminated with fatal OOM (Allowed memory size 134217728 bytes exhausted) while exercising routes (routes/api.php / routes/web.php)
- Action taken: created temporary CLI ini and Herd override ini files to raise `memory_limit` to 512M for local test runs. This allowed the suite to run further but many tests still failed.
- Final result (after memory override): 113 passed, 75 failed, 3 risky, 7 skipped (400 assertions) — run aborted again when memory was eventually exhausted in some contexts.

Key errors observed
-------------------
1. Out of memory (OOM)
   - Message: "Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes)"
   - Where: first seen in `routes/api.php` and `routes/web.php` during the full suite run.
   - Cause: default CLI PHP memory limit (128M) too low for the full suite in this environment.
   - Temporary mitigation applied: created local ini files to raise `memory_limit` to 512M for the Herd CLI PHP. Files created:
     - `tmp/php_cli_test.ini` (project)
     - `/Users/teamsolo/Library/Application Support/Herd/config/php/84/99-local-override.ini`
     - `/Users/teamsolo/Library/Application Support/Herd/config/php/84/zzz-local-memory-override.ini`
   - Note: these were added to the local environment during debugging and should be removed or committed according to repository policy.

2. Auth guard not defined
   - Error: "Auth guard [api] is not defined." (InvalidArgumentException)
   - Where: multiple security reporting and API tests (e.g. `Tests\\Feature\\API\\SecurityReportControllerTest`)
   - Likely causes:
     - Test environment `config/auth.php` may be missing the `api` guard definition in testing context.
     - A package expected in tests (e.g. JWT provider) may not be bootstrapped in testing environment.

3. Database schema mismatches / missing columns
   - Examples:
     - SQLSTATE[42S22]: Unknown column 'name' in 'field list' when inserting into `security_reports` (test expected `name` column)
     - Unknown column 'terminal_uid' when inserting into `pos_terminals`
     - Foreign key failures when inserting `tenants` (missing `company_id` reference) — indicates seed/migration/fixture mismatch
   - Likely causes:
     - Test database schema (migrations) not fully applied or out of sync with tests' expectations.
     - Factories or tests assume columns that exist in the target DB in CI but not on the local test DB.

4. Unique constraint violations in tests
   - Examples: Duplicate entry 'test@example.com' for `users.users_email_unique`, Duplicate company customer codes (e.g. 'TESTCUST-001').
   - Likely causes:
     - Persistent test DB state between tests or factories creating non-unique values.
     - `RefreshDatabase`/`DatabaseTransactions` not applied or misconfigured in some tests.

5. Missing classes / packages
   - Examples: `Class "Tymon\\JWTAuth\\Facades\\JWTAuth" not found` — indicates JWT package (tymon/jwt-auth) not available in runtime or not auto-discovered in tests.
   - Action: verify composer dependencies and any service provider registration required for testing.

6. Filesystem errors in tests
   - Example: file_put_contents(storage_path('app/exports/report-1.pdf')) failing because the directory doesn't exist.
   - Mitigation: ensure `storage/app/exports` exists before running tests or tests should create temp files via `Storage::fake()`.

Reproduction steps used
----------------------
1. Run full suite (initial):

   APP_DEBUG=1 php artisan test

2. Increase local CLI memory (ad-hoc):

   PHP_BIN="/Users/teamsolo/Library/Application Support/Herd/bin//php"
   "$PHP_BIN" -c "/path/to/tmp/php_cli_test.ini" -d memory_limit=512M artisan test

3. Used PHP_INI_SCAN_DIR workaround to load project tmp ini and increase memory without editing Herd's main php.ini.

Relevant log excerpts
---------------------
- OOM excerpt:

  Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes) in /.../routes/api.php on line 85

- Auth guard excerpt:

  InvalidArgumentException: Auth guard [api] is not defined. (stack trace pointing to Illuminate/Auth/AuthManager)

- SQL excerpt (example):

  SQLSTATE[42S22]: Column not found: 1054 Unknown column 'name' in 'field list' (Connection: mysql, SQL: insert into `security_reports` ... )

What I changed locally (temporary)
---------------------------------
- Added `tmp/php_cli_test.ini` in the project to raise `memory_limit` during ad-hoc runs.
- Created Herd override files under Herd's scan dir to raise `memory_limit` for the Herd PHP binary used by this workspace. These were added to allow the suite to run further and capture additional failures.

Suggested next steps (actionable)
--------------------------------
1. Reset test DB and re-run migrations
   - Run: `php artisan migrate:fresh --env=testing` (or the project's preferred test DB reset command) and re-run the failing tests. This should address many schema-related errors.

2. Ensure test fixtures/factories generate unique values
   - Review factories used by failing tests and add `faker->unique()` or reset sequences where needed.

3. Ensure required packages and providers are available in testing
   - Confirm `tymon/jwt-auth` (or other auth packages) is installed and that relevant service providers are registered in `config/app.php` or `phpunit.xml` bootstrap for testing.

4. Create missing storage paths in test setup
   - Add setup code (or `setUp()` helpers) to create `storage/app/exports` or use `Storage::fake()` in tests that write files.

5. Convert high-memory tests to smaller batches for local TDD
   - Run focused failing test files and iterate (e.g. `php artisan test tests/Feature/Auth/AuthenticationTest.php`).

6. Revert temporary local ini overrides when the team agrees
   - If these files should not persist, remove them from the environment and/or add them to `.gitignore` (if acceptable by team policy).

Owners / Recommended assignees
-----------------------------
- CI / DevOps: validate and standardize CLI PHP memory in local dev images or CI runners (set memory_limit >= 512M for full-suite runs)
- Backend: review migrations and factories to ensure test DB schema matches expectations
- QA / Test owners: add filesystem fakes and ensure tests isolate side effects

References
----------
- Related docs in repo:
  - `_md/TRANSACTION_PIPELINE_TEST_SUITE.md` (contains guidance mentioning increasing memory_limit)
  - `docs/ISSUE_FULL_SUITE_FAILURES.md` (existing guidance)

If you want, I can:
- Run a targeted failing test (pick a file) and produce a more focused failure report — or
- Reset test DB and re-run the entire suite now that memory_limit is increased.

-- recorded-by: automated-debug-runner
-- date: 2025-10-28
