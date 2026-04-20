# Risk Register

This document identifies, assesses, and tracks risks that could impact the project's success.

## Risk Matrix
| ID | Risk Description | Impact | Probability | Mitigation Strategy | Status |
|----|------------------|--------|-------------|---------------------|--------|
| R-01 | Context degradation due to long chat threads. | High | Medium | Use `task-ledger.md` and durable rule files. | Active |
| R-02 | Cryptographic bypass during fast-tracked fixes. | Critical | Low | Hardened Rule 06 and automated checksum tests. | Mitigated |
| R-03 | Multi-tenant isolation failure. | Critical | Low | Enforce mandatory Layer 2 resolution in all PRs. | Active |
| R-04 | Tool misuse (destructive commands). | Medium | Medium | Implement Tool Governance (Rule 04) and gated plans. | Mitigated |

## Risk History
- **2026-04-20**: Initialized registry to manage governance-level risks.
