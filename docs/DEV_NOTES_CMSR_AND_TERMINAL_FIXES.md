# Developer Reference: March 2026 Core Platform Updates

This document serves as a comprehensive technical reference for the recent revamp of core platform features, ranging from ingestion logic to reporting and management UI.

---

## 🕒 1. Ingestion & Timestamp Normalization
**Objective:** Resolve the 8-hour shift in transaction logs caused by terminal timezone mislabeling.

### 8-Hour Shift Correction
- **Issue:** Terminals appended a `Z` (UTC) suffix to local time strings, causing the server to treat them as UTC and then shift them to local time incorrectly.
- **Fix:** Implemented `shiftTimezone` logic in `TransactionController.php`.
- **Logic:** Detects `Z` suffix. If the offset is exactly 0 but labeled as UTC, it shifts the value back to `Asia/Manila` to preserve the actual local occurrence time.
- **Affected Endpoints:** `batchStore`, `storeOfficial`, `processTransaction`.

### UI Integration
- Standardized all React components to use `transaction_timestamp` (stored in DB) as the source of truth.
- Removed confusing "(Local)" labels that previously attempted to compensate for the shift.

---

## 📊 2. Commercial Reporting (CMSR)
**Objective:** Align the Certified Monthly Sales Report (CMSR) with the official Excel "Source of Truth" template.

### Financial Formula Calibration
- **Regular Discounts:** Captured `discount_total` (regular discounts) in `FinanceCalculationService.php`.
- **Net Sales Formula:** `Gross - (All Discounts + Exempt + Other Taxes)`.
- **Tax Mapping:** Expanded VAT-exempt detection to catch `VAT-EXEMPT`, `EXEMPT`, and `VATEXEMPT` string variants.
- **VAT Derivation:** Forced VAT to be derived as exactly 12% of the Vatable Base to ensure Z-reading alignment.
- **Column Sync:** Explicitly mapped `vatable_sales` to `net_ex_vat` to match the Excel template's Column B.

---

## 🖥️ 3. Dashboard Performance & Accuracy
**Objective:** Resolve "Blank/Zero" dashboard states and improve data visualization.

### Data Mapping Fixes
- **Total Revenue KPI:** Wired to `total_sales.current` instead of a broken generic mapping.
- **Currency Standardization:** Standardized all monetary displays to `₱ (PHP)`.
- **Caching Logic:** Fixed a race condition in `DashboardService.php` where stale keys prevented charts from populating.
- **Metric Periodicity:** Corrected the date grouping logic to ensure transactions appearing at the end of the day or start of a new month are correctly binned.

---

## 🏢 4. Tenant & Terminal Management (Security Revamp)
**Objective:** Implement robust management tools and fix registration bottlenecks.

### Tenant Management Suite
- **New Feature:** Implemented a full CRUD suite for Tenants, including trade names, operator IDs, and status tracking.
- **UI/UX:** Enhanced table views with refined filter bars and material icon grouping.
- **Sidebar Integration:** Mapped management routes to the main sidebar for easier administrative access.

### Terminal Registration (422 Error Fix)
- **Visibility:** Fixed a CSS bug where the "Register Terminal" button had `opacity: 0`.
- **Specific Error Reporting:** The React frontend now parses and displays detailed validation errors (e.g., "Duplicate Serial Number") instead of a generic failure message.
- **Audit Logging:** Implemented specific `Log::warning` captures for failed registration attempts, including the rejected payload for debugging.

---

## 🛠️ Key Technical Files
| Component | Primary Files |
|-----------|---------------|
| **Ingestion** | `TransactionController.php`, `WebAppForwardingService.php` |
| **Finance** | `FinanceCalculationService.php`, `Transaction.php` |
| **Dashboard** | `DashboardService.php`, `DashboardPage.jsx`, `TransactionChart.jsx` |
| **Identity** | `TerminalTokenController.php`, `TerminalTokenPage.jsx`, `Tenant.php` |

---
*Last Updated: March 12, 2026*
