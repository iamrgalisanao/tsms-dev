# TSMS Cloud Infrastructure Optimization Proposal

## 1. Executive Summary
The TSMS (Transaction Management System) for PITX is a mission-critical, high-performance ingestion engine. To maintain its **2-minute reconciliation SLA** and **sub-second ingestion latency targets**, the underlying infrastructure must be optimized for high-concurrency background processing and regional data sovereignty.

This proposal evaluates leading cloud providers based on the specific technical requirements of the TSMS A.N.T. architecture and the operational context of the Philippines.

---

## 2. Technical Architecture Requirements

The TSMS relies on a high-discipline PHP 8.2/Laravel 11 stack requiring:
*   **Massive Concurrency**: Handling 8 independent Redis sharded queues (`s0-s7`) simultaneously.
*   **Low-Latency Interoperability**: Seamless sub-second connection between POS terminals (Ingress) and the TSMS API.
*   **Database Resilience**: Dual-database strategy (OLTP for transactions, Read-Replica for heavy financial reporting).
*   **Regional Compliance**: Alignment with the **Philippines Data Privacy Act (RA 10173)** regarding data residency and security hygiene.

---

## 3. Cloud Provider Comparison

| Criteria | **Alibaba Cloud** | **AWS (Amazon)** | **Huawei Cloud** |
| :--- | :--- | :--- | :--- |
| **Data Center** | **Manila, PH** | Singapore | **Manila, PH** |
| **Latency** | **Ultra-Low (<10ms)** | Low (30-50ms) | **Ultra-Low (<10ms)** |
| **Managed Services** | Mature (RDS, Redis) | World-Class (RDS, ElastiCache) | Growing local stack |
| **Best For...** | **Max Performance & Compliance** | Ecosystem Maturity | Local Cost Efficiency |

---

## 4. Recommended Infrastructure "Tier-1" Stack

Regardless of the selected provider, the following configuration is recommended for the production environment:

### A. Application Tier (Scalability)
*   **Service**: Managed Container Orchestration (e.g., Fargate or K8s).
*   **Policy**: Independent scaling for worker nodes to handle peak-hour transaction spikes without impacting the web API response times.

### B. Database Tier (Data Integrity)
*   **Configuration**: High-availability MySQL 8.4 Cluster with **Multi-AZ Replication**.
*   **Optimization**: Provisioned IOPS (SSD-based) to ensure consistent write performance during high-volume ingestion.

### C. Cache & Queue Tier (The Motor)
*   **Service**: Managed Redis (6.x or 7.x).
*   **Role**: Serves as the central sharding engine for Horizon. Multi-node clusters are mandatory to prevent single points of failure in the queue pipeline.

### D. Security Layer (Guardrails)
*   **Web Application Firewall (WAF)**: Active protection against transaction-level brute force.
*   **Data Encryption**: Mandatory encryption-at-rest for PII and transaction logs.

---

## 5. Strategic Recommendations

### Primary Recommendation: Alibaba Cloud (Manila Zone)
**Technical Rationale**: For a transit hub like PITX, every millisecond counts toward terminal throughput. By hosting in the local Manila region, TSMS achieves **near-zero latency** for local hardware while natively satisfying all **Republic Act 10173** data residency requirements.

### Secondary Recommendation: AWS (Singapore Region)
**Technical Rationale**: If the priority shifts toward global developer talent pools and the most robust ecosystem of third-party integrations, AWS Singapore is the standard enterprise choice. However, it will incur slight latency overhead (approx. 40ms) compared to local hosting.

---

## 6. Implementation Roadmap
1.  **Phase 1**: Regional Latency Benchmarking (Manila vs. Singapore).
2.  **Phase 2**: Sizing & Resource Profiling based on current transaction volumes.
3.  **Phase 3**: Automated Provisioning (Infrastructure as Code) for multi-tenant isolation.
4.  **Phase 4**: Security Hardening & Penetration Testing.

---
*Standalone Source of Truth. Immutable Auditability. Multi-Tenant Excellence.*
