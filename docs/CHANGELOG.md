# Changelog

All notable changes to the TSMS project will be documented in this file.

## [Unreleased] - 2026-03-20

### Added
- **Real-Time Transaction Processing**: Restored immediate `ProcessTransactionJob` dispatching in `TransactionController` for `storeOfficial` and `batchStore` endpoints.
- **Queue Sharding**: Implemented sharded queue routing (`transaction-processing:s0-s7`) based on `tenant_id` to ensure load balancing and fairness across tenants.
- **Developer Experience**: Added `getTransactionId()` public getter to `ProcessTransactionJob` to facilitate automated testing.
- **Automated Verification**: Created `tests/Feature/API/V1/RealTimeDispatchTest.php` to ensure regression-free restoration of real-time processing.

### Changed
- **Latency Optimization**: Reduced transaction processing latency from ~5 minutes (watchdog-based) to under 2 seconds (event-driven).
- **Concurrency Safety**: Forced `afterCommit()` on job dispatches to ensure data consistency before background processing starts.

### Fixed
- **Queue Bottleneck**: Resolved the issue where transactions were staying in `PENDING` status for up to 5 minutes due to missing immediate dispatch logic originally lost during the March 16th refactor.
- **Checksum Validation Failure**: Restored staging compatibility by implementing a V2.0/V2.1 **Multi-Version Fallback** in `PayloadChecksumService.php`. This allows legacy POS systems (V2.0 float-based) and newer systems (V2.1 string-based) to coexist.
- **V2.1 Specification Gaps**: Corrected `PayloadChecksumService.php` to include `receipt_no` in monetary formatting and added a required `is_numeric()` guard per the V2.1 Draft Guidelines.

### Security
- **Batch Submission Disablement**: Explicitly disabled batch transaction submission in both `storeOfficial` and `batchStore` endpoints per client agreement for Phase 1. Both endpoints now return a `422 Unprocessable Entity` response if batch arrays are detected.
- **Audit Logging**: Enhanced rejection audit events for disabled batch submissions.
