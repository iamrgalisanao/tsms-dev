# TSMS-DEV Instructions (BMad Fullstack Bundle Adapted)

## Project Context
TSMS-DEV is a **Laravel transactional system** integrating POS, bulk forwarding, and audit logging.  
It uses a **multi-component architecture**: API, CLI, MCP (memory agent), and UI services.  
Transaction data flows via **unified schemas** and is processed with **queues (Horizon/Redis)**.  

👉 This project is aligned with the **BMad-Method** using the **`team-fullstack` web-bundle**.

---

## Team-Bundle Reference
For **Web UI planning** (Gemini, ChatGPT, Claude), load:  

```
/web-bundles/dist/teams/team-fullstack.txt
```

This bundle activates the **Fullstack Agent Team**:  

- **Analyst** → Documents existing Laravel system & integration  
- **PM** → Creates/updates PRD (brownfield for enhancements)  
- **Architect** → Defines Laravel + Redis queue architecture  
- **UX Expert** → UI/UX for POS dashboards & Horizon access  
- **PO** → Splits epics/stories, validates acceptance criteria  

📌 Use this instead of loading agents one by one — the **bundle provides orchestration + workflows**.

---

## Workflows
With **team-fullstack**, use **Brownfield Workflows** for TSMS-DEV:  

1. **Brownfield Fullstack** → Laravel backend + UI changes  
2. **Brownfield Service** → Queueing, MCP, audit log updates  
3. **Brownfield UI** → POS-facing Horizon dashboards  

👉 Start with:  

```
*workflow-guidance
```

---

## Data Flow & Schema Rules
- **Bulk Forwarding Schema v2.0**: All transactions must wrap in unified envelope.  
- **Batch Rule**: One `(tenant_id, terminal_id)` pair per batch.  
- **Circuit Breaker**: Applies only to retryable/network failures.  

📌 Architect agent documents schema enforcement.  
📌 PO agent validates acceptance criteria around schema constraints.  

---

## Developer Workflow (Bundle-Aligned)

1. **Setup**  
   - Run: `composer install`, `npm install`, and `scripts/*`  
   - `/analyst document-project` to capture Laravel structure into brownfield-doc  

2. **Migrations**  
   - `php artisan migrate --force`  
   - PO agent maps migrations into story tasks  

3. **Testing**  
   - `php artisan test`  
   - QA review inside fullstack cycle ensures coverage (`tests/Feature/*`)  

4. **Queue Management**  
   - Horizon: `php artisan horizon:terminate` to restart workers  
   - Admin/Ops-only dashboard → validated by PO agent  

5. **Memory Agent**  
   - Run Cipher MCP (VS Code tasks)  
   - `/architect create-brownfield-architecture` to document MCP integration  

6. **Feature Flags**  
   - Config-driven (`tsms.testing.capture_only`)  
   - Documented in PRD  

---

## Conventions (Bundle-Mapped to PO Agent Rules)
- Transaction schemas → versioned; consumers updated before expansion.  
- Failed jobs → retained 30 days.  
- Horizon access → restricted + allowlisted.  
- Jobs → tagged (`transaction:pk`, `domain:forwarding`) for tracking.  

---

## Integration Points
- **Cipher MCP agent** (`memAgent/`)  
- **External POS providers** (see `_md/POS_provider_integration_checklist.md`)  
- **Auth** via Laravel Sanctum (JWT removed).  

📌 Analyst + Architect agents track these in bundle PRD & architecture docs.

---

## Examples
- Void tests:  

```
php artisan test tests/Feature/VoidTransactionTest.php --verbose
```

- Load knowledge: VS Code task `"TSMS: Load Project Knowledge into Cipher Memory"`  
- Start MCP: `"TSMS: Start Cipher Memory Agent (MCP Mode)"`

---

## Next Steps
1. Load **bundle**:  

```
/web-bundles/dist/teams/team-fullstack.txt
```

2. For enhancements: `/pm create-doc brownfield-prd`  
3. For system docs: `/analyst document-project`  
4. For integration design: `/architect create-brownfield-architecture`  
5. For backlog refinement: `/po create-story`  

---

⚡ By referencing `team-fullstack` directly, TSMS-DEV inherits the **BMad orchestrated workflow**, ensuring PRDs, architecture docs, user stories, and QA all align under a single web-bundle.