# Assumptions Register

| ID | Category | Assumption Description | Impact | Status |
|----|----------|------------------------|--------|--------|
| A1 | Technical | Every transaction is unique by `tenant_id + transaction_id`. | Core Data Model | Verified |
| A2 | Security | POS system tokens are rotated and stored securely by vendors. | API Security | Open |
| A3 | Infra | Redis is available for sharding queues `s0-s7`. | Scaling/Performance | Verified |
| A4 | Business | POS providers will implement polling logic for `/status`. | Ingestion UX | Open |
| A5 | Compliance | All transactions submitted to TSMS require active auditing. | System Purpose | Verified |
| A6 | Technical | The `vendor/autoload.php` is generated and PSR-4 compatible. | App Bootstrapping | Verified |
