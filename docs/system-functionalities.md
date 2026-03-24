# TSMS Functionality Matrix

This document provides a comprehensive overview of the TSMS (Transaction Management System) application's functionalities, organized by operational domain. Each section details the relevant API routes, models, controllers, and configuration settings.

---

## 1. Transaction Ingestion Engine

The core of the TSMS application, responsible for receiving, validating, and persisting transaction data from POS terminals.

| Component | Details |
| :--- | :--- |
| **Functionality** | Official V2.1 Ingestion, Legacy V2.0 Fallback, Batch Processing, SHA-256 Checksum Validation, Normalization. |
| **API Routes** | `POST /api/v1/transactions/official`, `POST /api/v1/transactions/batch`, `GET /api/v1/transactions/{id}/status`. |
| **Controllers** | `App\Http\Controllers\API\V1\TransactionController`. |
| **Models** | `App\Models\Transaction`, `App\Models\TransactionSubmission`, `App\Models\TransactionValidation`. |
| **Configuration** | `config/tsms.php` (`validation` block), `config/ingestion.php`. |
| **Services** | `App\Services\TransactionIngestService`, `App\Services\PayloadChecksumService`, `App\Services\TransactionValidationService`. |

---

## 2. Multi-Tenant Isolation (A.N.T. Architecture)

Ensures that transaction data is strictly isolated by tenant across all system layers.

| Component | Details |
| :--- | :--- |
| **Functionality** | Global Tenant Scoping, Terminal-to-Tenant Binding, isolated audit trails. |
| **API Routes** | `GET /api/dashboard/metrics` (Tenant-scoped), `GET /api/transactions/logs` (Tenant-scoped). |
| **Controllers** | `App\Http\Controllers\DashboardController`, `App\Http\Controllers\TenantController`. |
| **Models** | `App\Models\Tenant`, `App\Models\PosTerminal`. |
| **Middleware** | `App\Http\Middleware\EnsureTenantContext` (Implicit via Scopes). |
| **Traits** | `App\Traits\BelongsToTenant`. |

---

## 3. Authentication & RBAC

Governs access to the system for both administrative users and POS terminals.

| Component | Details |
| :--- | :--- |
| **Functionality** | Sanctum-based API Security, Role-Based Access Control (RBAC), Ability-based Routing. |
| **API Routes** | `POST /api/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/terminal`. |
| **Controllers** | `App\Http\Controllers\API\Auth\AuthController`, `App\Http\Controllers\API\V1\TerminalAuthController`. |
| **Models** | `App\Models\User`, `App\Models\TerminalToken`, `Spatie\Permission\Models\Role`. |
| **Configuration** | `config/auth.php`, `config/sanctum.php`, `config/permission.php`. |
| **Gates/Policies** | `App\Providers\AuthServiceProvider` (defines `admin`, `manager`, `export-transaction-logs` gates). |

---

## 4. Operational Monitoring & Audit Logs

Provides real-time visibility into the health and integrity of the ingestion pipeline.

| Component | Details |
| :--- | :--- |
| **Functionality** | Transaction logs, Submission Events, Incident tracking, Security events. |
| **API Routes** | `GET /api/v1/submission-events`, `GET /api/v1/incidents`, `GET /api/transactions/logs`. |
| **Controllers** | `App\Http\Controllers\API\V1\SubmissionEventController`, `App\Http\Controllers\API\V1\IncidentController`, `App\Http\Controllers\TransactionLogController`. |
| **Models** | `App\Models\SubmissionEvent`, `App\Models\Incident`, `App\Models\TransactionLog`, `App\Models\SecurityEvent`. |
| **Configuration** | `config/tsms_error_catalog.php`. |
| **Services** | `App\Services\TransactionLogService`. |

---

## 5. Administrative & Maintenance Tools

Critical utilities for managing system state and correcting operational defects.

| Component | Details |
| :--- | :--- |
| **Functionality** | Terminal Token Management, DLQ (Dead-Letter Queue) Management, Retry History. |
| **API Routes** | `GET /api/terminals/tokens`, `GET /api/v1/admin/failed-jobs`, `GET /api/v1/retry-history`. |
| **Controllers** | `App\Http\Controllers\TerminalTokenController`, `App\Http\Controllers\API\V1\FailedJobController`, `App\Http\Controllers\API\V1\RetryHistoryController`. |
| **Models** | `App\Models\TransactionJob`, `App\Models\RetryHistory`, `App\Models\TerminalToken`. |
| **Configuration** | `config/tsms.php` (`dlq` block), `config/retry.php`. |
| **Tools** | `tools/validate_tsms_payload.php`. |

---

## 6. Dashboard & Reporting

High-level visualizations and data exports for business and operational oversight.

| Component | Details |
| :--- | :--- |
| **Functionality** | KPI aggregation, terminal performance graphs, CSV/XLSX exports. |
| **API Routes** | `GET /api/dashboard/metrics`, `GET /api/transactions/logs/export`. |
| **Controllers** | `App\Http\Controllers\DashboardController`, `App\Http\Controllers\TransactionLogController`. |
| **Models** | `App\Models\Transaction`, `App\Models\PosTerminal`. |
| **Services** | `App\Services\DashboardService` (Implicit), `App\Services\ReportingService` (Implicit). |

---

## 7. WebApp Read-only API (Forwarder Alternative)

Internal M2M API for secure data consumption by external web applications.

| Component | Details |
| :--- | :--- |
| **Functionality** | Secure transactions access, summary counts, sales reports for external analytics. |
| **API Routes** | `GET /api/v1/webapp/transactions`, `GET /api/v1/webapp/reports/sales`. |
| **Controllers** | `App\Http\Controllers\Api\Webapp\TransactionController`, `App\Http\Controllers\Api\Webapp\ReportsController`. |
| **Models** | `App\Models\Transaction`. |
| **Security** | `ensure.webapp.token` middleware. |
