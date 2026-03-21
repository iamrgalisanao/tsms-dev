# TSMS Project Dynamics Orchestrator

This document orchestrates the establishment of a high-discipline, multi-layered technical memory and workflow system. It is a **self-calibrating template** designed to adapt based on the discovery phase.

---

## 1. Discovery & Calibration Phase
> [!IMPORTANT]
> **AI INSTRUCTIONS**: Initiate the discovery phase by asking these questions. **DO NOT** generate content for the subsequent sections until the user has provided high-fidelity context. Use the answers to "calibrate" the technical rigor and architectural layers.

### A. System Vision & Strategy
1. What is the core mission of this application?
2. What are the top 3 architectural invariants or non-negotiables?
3. Who are the primary tenants or end-users?

### B. Functional Domain
1. What is the primary data entity and its lifecycle?
2. What are the critical integration points?
3. What security or compliance standards must be followed?

### C. AI Persona & Role Calibration
Based on the project's complexity, which AI persona is required?
- [ ] **Lead Architect**: (Focus: A.N.T. layers, scalability, and structural integrity)
- [ ] **Senior Fullstack Dev**: (Focus: Feature implementation and SOQT alignment)
- [ ] **Security Auditor**: (Focus: PII leaks, tenant isolation, and cryptographic integrity)
- [ ] **Compliance Officer**: (Focus: Regulatory logic and audit logs)

---

## 2. Contextual Memory Structure (Essential Only)
Establish the `docs/context/` directory using **kebab-case** naming conventions.

### 2.1 Essential Subdirectories
- **`features/`**: Detailed specs (`<feature-name>-spec.md`) used as the source of truth for new logic.
- **`fixes/`**: Technical post-mortems (`<issue-name>-fix.md`) documenting regressions.
- **`research/`**: Exploratory analysis (`<topic>-research.md`) informing the discovery phase.
- **`screenshots/`**: Visual audit trail for UI/UX approvals.

### 2.2 Living Documents
- **`project-overview.md`**: The master spec (System Vision, Tech Stack, Data Models).
- **`current-feature.md`**: A dynamic tracker for the active task's progress and blockers.

---

## 3. The Core Template (SOQT)
Once calibrated, populate the following "Source of Truth Quintet" files:

### 3.1 Strategy: `gemini.md` (Strategy)
### 3.2 Functional: `spec.md` (Functional Rules)
### 3.3 Workflow: `operational_protocol.md` (Operational Protocol)
### 3.4 Execution: `.agents/skills/` (Specialized Skills)
### 3.5 Compliance: `findings.md` (Compliance Log)

---

## 4. The Forge: Advanced Components

### 4.1 Sub-Agent Forge
> [!TIP]
> Create specialized AI reviewers in `.agents/subagents/`.

```markdown
# [Sub-Agent Name] - [Focus Area]
## 1. Goal: [PII Protection / Architectural Integrity / UI-UX Critic]
## 2. Mandatory Rules: [Insert calibrated rules based on Discovery]
## 3. Trigger: [Specific markers in task.md]
```

### 4.2 Skill Engine
> [!TIP]
> Create deterministic scripts in `.agents/skills/`.

```markdown
# [Skill Title]
---
name: [identifier]
description: [Short purpose summary]
argument-hint: [action1|action2]
---
## 1. Commands: [Link to internal tools or scripts]
## 2. Reference: [Link to spec.md/gemini.md]
```

---

## 5. Implementation Treeview (Example)
```text
.
├── .agents/
│   ├── skills/              # Specialized Task Executables
│   ├── subagents/           # Automated Compliance Scanners
│   └── workflows/           # Standardized Step-by-Step Guides
├── docs/
│   ├── archive/             # Stale/Legacy Documentation
│   ├── context/             # Technical Memory
│   │   ├── features/        # Feature Specs
│   │   ├── fixes/           # Technical Fix Records
│   │   ├── research/        # Domain Discovery
│   │   └── screenshots/     # Visual Audit Trail
│   └── standards/           # Coding & Security Policies
├── gemini.md                # System Strategy (Layer 1)
├── spec.md                  # Functional Rules (Layer 1)
├── operational_protocol.md  # Core Workflow (Layer 2)
└── findings.md              # Live Compliance Log (Layer 3)
```

---

## 6. Initialization Workflow
1. **Calibrate**: Run the Questionnaire and select **AI Persona**.
2. **Structure**: Create the directory tree (Essential Only).
3. **Instantiate**: Generate the SOQT baseline.
4. **Active Work**: Initialize `current-feature.md` and appropriate sub-agents.
