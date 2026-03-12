# Developer Reference: CMSR, Terminal Registration, and Timestamp Fixes (March 2026)

This document provides a technical summary of recent critical fixes to the TSMS-dev platform, focusing on final report accuracy and system ingestion stability.

## 1. Timestamp Normalization (8-Hour Shift)
**Issue:** Terminal transactions were appearing 8 hours later in the UI than they occurred (e.g., 6:00 PM local time appearing as 2:00 AM next day).
**Root Cause:** Terminals were sending local time in ISO8601 format but appending a `Z` suffix, tricking the server (UTC) into believing the time was already in UTC.
**Fix:**
- Implemented `shiftTimezone` logic in `TransactionController.php`.
- The system now detects the `Z` suffix, and if missing a timezone offset, shifts the time back to the defined local timezone (Asia/Manila) for consistent storage.
- **Affected Paths:** `batchStore`, `storeOfficial`, and `processTransaction`.

## 2. CMSR Report Calculations
**Issue:** Discrepancies in VAT mapping and missing "Regular Discounts" leading to incorrect net sales and VAT totals.
**Fixes:**
- **VAT-Exempt Detection:** Expanded detection patterns in `TransactionController` to include `VAT-EXEMPT`, `EXEMPT`, and `VATEXEMPT`.
- **Regular Discounts:** Updated `FinanceCalculationService.php` to include `discount_total` in the "Financial Truth" formulas.
- **Vatable Base:** Ensured `Net Sales = Gross - (Total Discounts + Exempt + Other Taxes)`.
- **VAT Derivation:** Forced VAT to be derived as exactly 12% of the Vatable Base to match Z-reading expectations.
- **Column Alignment:** `vatable_sales` is now explicitly mapped to `net_ex_vat` to ensure the Excel mapping (Column B) is correct.

## 3. Terminal Registration Error (422)
**Issue:** 422 Unprocessable Content error during terminal registration was opaque to users, and the "Register Terminal" button was invisible.
**Fixes:**
- **UI Visibility:** Removed `opacity: 0` bug from `TerminalTokenPage.jsx` button.
- **Error Reporting:** Enhanced React error handling to parse and display specific backend validation errors (e.g., "Serial number already taken").
- **Audit Logging:** Added `Log::warning` in `TerminalTokenController::apiStore` to capture validation failures (errors + payload) for backend debugging.

## Key Files to Reference
- `app/Http/Controllers/API/V1/TransactionController.php` (Ingestion Logic)
- `app/Services/Reports/FinanceCalculationService.php` (Financial Formulas)
- `app/Http/Controllers/TerminalTokenController.php` (Security & Audit)
- `resources/js/Pages/TerminalTokenPage.jsx` (Frontend UI)
