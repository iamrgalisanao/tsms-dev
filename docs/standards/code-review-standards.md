# Code Review Standard: TSMS Transactional Integrity

## Review Layers

### Layer 1: Architecture (ANT)
- **Architecture**: Does the code follow the 3-Layer structure (Architecture, Navigation, Tools)?
- **Navigation**: Is there business logic in the `Navigation` layer (Controllers/Middleware) that belongs in `Services`?
- **Tools**: Are verification tools or scripts provided for significant logic changes?
- **SOPs**: Are related standards (e.g., `gemini.md`, `spec.md`) updated?

### Layer 2: Transactional Logic & Domain
- **Tenant Isolation**: Is the `BelongsToTenant` trait applied? Are raw queries manually scoped with `tenant_id`?
- **Dual-Checksum**: Does the logic comply with V2.1/V2.0 checksum standards?
- **Auditability**: Are state changes (Voids, Refunds, Ingestion) triggered for the Audit/Validation Engine?
- **Data Integrity**: Is there any "UI-calculated" financial truth being stored without engine-side validation?

### Layer 3: Compliance (RAIN)
- **R**esponsibility: Is the terminal/actor identity verified via Sanctum `pos_api`?
- **A**udit: Is every state change logged to the Transaction Validation Engine?
- **I**solation: Is there any risk of cross-tenant data leakage or storage overlap?
- **N**ecessity: Is the data captured limited to the [V2.1 Payload Specification]?

## Approval Gates
- **TSMS_CRITICAL**: Core ingestion, checksum logic, or financial calculation changes require **two** senior/architect approvals.
- **TSMS_ROUTINE**: Standard PR review from logic owners.
- **TSMS_EMERGENCY**: Hotfix logic requires post-deployment audit sync and a retrospective SOP update.
