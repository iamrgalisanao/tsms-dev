Title: Investigate full PHPUnit suite failures and memory exhaustion (follow-up)

Description
-----------
During focused validation work we stabilized `TransactionValidationTest` (22 tests passing). However, running the full PHPUnit suite produced many unrelated failures across multiple modules and a fatal PHP memory exhaustion error. This issue tracks triage work to bring the entire test suite back to green.

Observed symptoms
-----------------
- Many unit & feature tests failed (CircuitBreakerTest, JobProcessingServiceTest, Security reporting tests, Auth tests, TransactionProcessingTest, TransactionPipeline end-to-end tests, etc.).
- The test runner terminated with a fatal error: "Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes)" originating in routes/api.php during a full suite run.

Suggested triage steps
----------------------
1. Re-run the full test suite in CI or locally with increased PHP memory_limit (example: `php -d memory_limit=512M artisan test`) to confirm whether the memory exhaustion is environmental or reproducible across machines.
2. If increased memory allows the suite to run further, capture the full failure list and prioritize failing modules by impact.
3. Inspect the stack trace from the fatal error and instrument routes/api.php and bootstrapping to find large memory allocations (e.g., large config loads, heavy in-memory fixtures, or recursive calls).
4. Split the test suite into smaller groups and run in parallel (if CI supports) to isolate failing areas.
5. Assign owners for each failing test group and open targeted PRs to fix root causes.

Attachments
-----------
- Please attach the full PHPUnit log and any xdebug traces if available.

Priority: Medium (follow-up after this PR). The validation PR should not be blocked by unrelated suite stability issues; we recommend a triage ticket like this for the broader cleanup.
