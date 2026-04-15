# TSMS Reconciliation Playbook: POS Z-Reading Mismatches

This document provides guidance for PITX administrators and POS vendors when "Mathematical Inconsistencies" are reported or when TSMS totals do not align with local Z-readings.

## 📋 Executive Summary
Discrepancies between TSMS and POS Z-readings are usually NOT caused by data loss or calculation errors in TSMS. Instead, they are typically caused by **divergent accounting formulas** regarding VAT-exempt sales (Senior/PWD) and the handling of Voids.

---

## 🔍 Known Discrepancy Mechanisms

### 1. VAT-Exclusive Gross Sales (Senior/PWD Transactions)
*   **The Problem**: Many POS systems send the **VAT-exclusive** price as the `gross_sales` to TSMS for Senior/PWD transactions (to comply with BIR statutory rules).
*   **The Mismatch**: If the Z-reading's "Total Gross" includes the **Original VAT-inclusive Price**, TSMS will be **LOWER** than the Z-reading.
*   **Identification**: Check if `diff = (Z_Reading_Gross - TSMS_Gross)` equals exactly `12%` of the Senior/PWD sales volume.
*   **Remediation**: POS vendors should standardize on either VAT-inclusive or VAT-exclusive Gross across all reporting layers.

### 2. Denormalized "Phantom VAT"
*   **The Problem**: TSMS "captures" and denormalizes the `vat_amount` from the payload's tax block. Some POS systems send a `0.00` VAT price in the receipt but still list the "Theoretical VAT" in the tax audit block.
*   **The Mismatch**: TSMS reports the `gross_sales` AND the `vat_amount`. If the user reconciles using `Gross = Net + VAT`, the math will appear to "double count" or include ghost VAT that was never collected.
*   **Identification**: Compare the `transactions.vat_amount` column with the corresponding `transaction_taxes` rows.
*   **Remediation**: Payloads should only include `vat_amount` if it was actually collected from the customer.

### 3. "Gross vs. Net of Voids" Reporting
*   **The Problem**: TSMS currently calculates "Total Revenue" as `SUM(gross_sales)` for all records, including those later marked as `voided_at`.
*   **The Mismatch**: Most Z-readings **subtract Voids** from the Total Gross. TSMS counts the voids but does not automatically strip the money from the high-level dashboard metrics.
*   **Result**: TSMS will appear **HIGHER** than the Z-reading by the total amount of all voided transactions.
*   **Remediation**: Use the "Valid Only" filter in reports, or manually subtract `void_amount` from the `total_revenue` metric.

---

## 🛠️ Step-by-Step Investigation Guide (for Operations)

1.  **Check for Voids**: Run a query for the day in question to see if `void_count > 0`. If yes, subtract the voided amounts from the TSMS total.
2.  **Filter by Senior/PWD**: Identify the volume of Senior/PWD transactions. Check if the "Missing VAT" matches 12% of this volume.
3.  **Audit the Tax Block**: Review the `taxes` array in the raw JSON payload. Ensure `VATABLE_SALES + VAT_EXEMPT_SALES + VAT_AMOUNT = GROSS_SALES`.

---

## 🚩 Escalation Criteria
Escalate to Engineering ONLY IF:
1.  The `gross_sales` value in the TSMS database does not match the `gross_sales` value in the raw JSON payload (Ingestion Mutation).
2.  The number of transactions in TSMS is lower than the count in the Z-reading (Data Loss).
3.  Calculations fail even after accounting for Voids and Senior VAT-exemptions.
