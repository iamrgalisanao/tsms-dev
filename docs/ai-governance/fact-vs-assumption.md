# Fact vs Assumption

| Type | Description | Reference |
|------|-------------|-----------|
| **Fact** | TSMS uses Laravel 8.2+ with strict typing. | `spec.md`, Codebase |
| **Fact** | Multi-tenancy is enforced via global query scopes. | `spec.md` Section 3.1 |
| **Fact** | Single Transaction Rule: One tx per submission. | `spec.md` Section 6 |
| **Fact** | WebApp forwarding must remain permanently disabled. | `spec.md` Section 5.3 |
| **Assumption** | POS vendors will always submit transactions in FIFO order. | `spec.md` Section 6 |
| **Assumption** | The terminal hardware ID is immutable for the life of the record. | `spec.md` Section 4.1 |
| **Fact** | **[FACT 2026-04-10]** Discrepancies between TSMS and Z-readings (e.g., Famous Apr 9) are caused by: <br> - **Mechanism A**: POS sending VAT-exclusive Gross for Senior/PWD while Z-reading uses VAT-inclusive. <br> - **Mechanism B**: TSMS summing all `gross_sales` without subtracting voids in high-level dashboard metrics. <br> - **Mechanism C**: Denormalization of "theoretical" VAT from payloads into `vat_amount` column. | Analysis 2026-04-10 |
| **Assumption** | All POS vendors use the same rounding logic for PHP decimal precision. | System Design |
| **Assumption** | Ingestion errors reported by POS vendors are due to queue latency. | Conversation 047c7bb3 |
| **Fact** | Philippines DPA compliance is a project requirement. | `spec.md` Section 7 |
