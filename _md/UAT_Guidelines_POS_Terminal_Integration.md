# TSMS POS Terminal Integration - User Acceptance Testing (UAT) Guidelines

**Document Code**: TSMS-UAT-2025-001  
**Prepared for**: POS Terminal Providers  
**Date**: July 11, 2025  
**Version**: 1.0  
**Status**: Production Ready

---

## 1. Overview

This document provides comprehensive User Acceptance Testing (UAT) guidelines for POS Terminal providers integrating with the TSMS (Tenant Sales Management System). These guidelines ensure proper integration, data integrity, and system reliability before production deployment.

### 1.1 Document Purpose

- Guide POS providers through systematic integration testing
- Validate all integration requirements are met
- Ensure data accuracy and system reliability
- Provide clear acceptance criteria for production readiness

### 1.2 Prerequisites

Before beginning UAT, ensure you have:
- ✅ TSMS integration credentials (Sanctum bearer tokens, tenant_id, terminal_id)
- ✅ Access to TSMS testing environment
- ✅ POS terminal software ready for integration testing
- ✅ Network connectivity to TSMS endpoints
- ✅ Understanding of TSMS payload formats (single and batch)

---

## 2. UAT Environment Setup

### 2.1 TSMS Testing Environment

| Component | Details |
|-----------|---------|
| **Base URL** | `https://tsms-test.example.com` |
| **Authentication** | Laravel Sanctum Bearer Token |
| **API Version** | v1 |
| **Rate Limiting** | 1000 requests per hour (testing) |
| **Timezone** | UTC+08:00 (Asia/Manila) |

### 2.2 Required Test Data

Prepare the following test data for comprehensive testing:

```json
{
  "test_credentials": {
    "tenant_id": 999,
    "terminal_id": 888,
    "sanctum_token": "your-test-sanctum-bearer-token"
  },
  "test_transactions": {
    "simple_sale": { "base_amount": 1000.00 },
    "with_discounts": { "base_amount": 1500.00, "discounts": 150.00 },
    "with_taxes": { "base_amount": 2000.00, "vat": 240.00 },
    "complex_transaction": { "base_amount": 3000.00, "adjustments": "multiple", "taxes": "multiple" },
    "edge_cases": { "minimum_amount": 0.01, "maximum_amount": 999999.99 }
  }
}
```

---

## 3. Phase 1: Basic Connectivity Testing

### 3.1 Health Check Verification

**Test ID**: UAT-CONN-001  
**Objective**: Verify basic connectivity to TSMS

**Test Steps**:
1. Send GET request to `/api/v1/healthcheck`
2. Verify response status code is 200
3. Verify response contains system status information

**Expected Response**:
```json
{
  "status": "healthy",
  "timestamp": "2025-07-11T10:00:00+08:00",
  "version": "1.0",
  "services": {
    "database": "healthy",
    "queue": "healthy",
    "cache": "healthy"
  }
}
```

**Acceptance Criteria**:
- ✅ Response status: 200 OK
- ✅ Response time: < 500ms
- ✅ All services status: "healthy"

---

### 3.2 Authentication Testing

**Test ID**: UAT-AUTH-001  
**Objective**: Verify Sanctum bearer token authentication

**Test Steps**:
1. Send POST request to any protected endpoint without Authorization header
2. Send POST request with invalid Bearer token
3. Send POST request with valid Bearer token

**Test Cases**:

| Test Case | Authorization Header | Expected Status | Expected Message |
|-----------|---------------------|-----------------|------------------|
| No token | None | 401 | "Unauthenticated" |
| Invalid token | `Bearer invalid-token` | 401 | "Unauthenticated" |
| Valid token | `Bearer {valid-sanctum-token}` | 200/422 | Success or validation error |

**Acceptance Criteria**:
- ✅ Proper authentication enforcement
- ✅ Clear error messages for auth failures
- ✅ Successful access with valid tokens

---

## 4. Phase 2: Single Transaction Testing

### 4.1 Basic Single Transaction Submission

**Test ID**: UAT-TXN-001  
**Objective**: Submit a simple single transaction

**Test Payload**:
```json
{
  "submission_uuid": "uat-single-001",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:00:00+08:00",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash",
  "transaction": {
    "transaction_id": "txn-uat-001",
    "transaction_timestamp": "2025-07-11T10:00:01+08:00",
    "base_amount": 1000.00,
    "payload_checksum": "computed-sha256-hash-txn"
  }
}
```

**Test Steps**:
1. Compute payload checksums correctly
2. Send POST request to `/api/v1/transactions/official`
3. Verify response status and content
4. Check transaction status via GET `/api/v1/transactions/{id}/status`

**Acceptance Criteria**:
- ✅ Response status: 200 OK
- ✅ Transaction accepted and processed
- ✅ Correct transaction_id returned
- ✅ Status check returns "VALID" or "PENDING"

---

### 4.2 Single Transaction with Adjustments

**Test ID**: UAT-TXN-002  
**Objective**: Submit transaction with discounts and adjustments

**Test Payload**:
```json
{
  "submission_uuid": "uat-single-002",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:05:00+08:00",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash",
  "transaction": {
    "transaction_id": "txn-uat-002",
    "transaction_timestamp": "2025-07-11T10:05:01+08:00",
    "base_amount": 1500.00,
    "payload_checksum": "computed-sha256-hash-txn",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": 100.00 },
      { "adjustment_type": "senior_discount", "amount": 50.00 },
      { "adjustment_type": "service_charge", "amount": 25.00 }
    ]
  }
}
```

**Acceptance Criteria**:
- ✅ All adjustments properly recorded
- ✅ Correct calculation and storage
- ✅ Response contains adjustment details

---

### 4.3 Single Transaction with Taxes

**Test ID**: UAT-TXN-003  
**Objective**: Submit transaction with various tax types

**Test Payload**:
```json
{
  "submission_uuid": "uat-single-003",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:10:00+08:00",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash",
  "transaction": {
    "transaction_id": "txn-uat-003",
    "transaction_timestamp": "2025-07-11T10:10:01+08:00",
    "base_amount": 2000.00,
    "payload_checksum": "computed-sha256-hash-txn",
    "taxes": [
      { "tax_type": "VAT", "amount": 240.00 },
      { "tax_type": "VAT_EXEMPT", "amount": 0.00 },
      { "tax_type": "OTHER_TAX", "amount": 20.00 }
    ]
  }
}
```

**Acceptance Criteria**:
- ✅ All tax types properly recorded
- ✅ Tax calculations stored correctly
- ✅ VAT and non-VAT amounts distinguished

---

### 4.4 Complex Single Transaction

**Test ID**: UAT-TXN-004  
**Objective**: Submit transaction with both adjustments and taxes

**Test Payload**:
```json
{
  "submission_uuid": "uat-single-004",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:15:00+08:00",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash",
  "transaction": {
    "transaction_id": "txn-uat-004",
    "transaction_timestamp": "2025-07-11T10:15:01+08:00",
    "base_amount": 3000.00,
    "payload_checksum": "computed-sha256-hash-txn",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": 300.00 },
      { "adjustment_type": "senior_discount", "amount": 150.00 },
      { "adjustment_type": "service_charge", "amount": 100.00 }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": 324.00 },
      { "tax_type": "OTHER_TAX", "amount": 30.00 }
    ]
  }
}
```

**Acceptance Criteria**:
- ✅ Complex transaction processed correctly
- ✅ All adjustments and taxes recorded
- ✅ Data integrity maintained

---

## 5. Phase 3: Batch Transaction Testing

### 5.1 Small Batch Transaction

**Test ID**: UAT-BATCH-001  
**Objective**: Submit small batch of transactions (3 transactions)

**Test Payload**:
```json
{
  "submission_uuid": "uat-batch-001",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T11:00:00+08:00",
  "transaction_count": 3,
  "payload_checksum": "computed-sha256-hash-batch",
  "transactions": [
    {
      "transaction_id": "txn-batch-001",
      "transaction_timestamp": "2025-07-11T11:00:01+08:00",
      "base_amount": 800.00,
      "payload_checksum": "computed-sha256-hash-txn1"
    },
    {
      "transaction_id": "txn-batch-002",
      "transaction_timestamp": "2025-07-11T11:00:02+08:00",
      "base_amount": 1200.00,
      "payload_checksum": "computed-sha256-hash-txn2",
      "adjustments": [
        { "adjustment_type": "promo_discount", "amount": 120.00 }
      ]
    },
    {
      "transaction_id": "txn-batch-003",
      "transaction_timestamp": "2025-07-11T11:00:03+08:00",
      "base_amount": 1500.00,
      "payload_checksum": "computed-sha256-hash-txn3",
      "taxes": [
        { "tax_type": "VAT", "amount": 180.00 }
      ]
    }
  ]
}
```

**Test Steps**:
1. Send POST request to `/api/v1/transactions/batch`
2. Verify all 3 transactions are processed
3. Check individual transaction statuses

**Acceptance Criteria**:
- ✅ All 3 transactions accepted
- ✅ Batch processing successful
- ✅ Individual transaction IDs returned
- ✅ All transactions queryable by status endpoint

---

### 5.2 Large Batch Transaction

**Test ID**: UAT-BATCH-002  
**Objective**: Submit larger batch (10+ transactions)

**Test Requirements**:
- Create payload with 10-15 transactions
- Mix of simple and complex transactions
- Various amounts, adjustments, and taxes
- Proper checksum calculation for each transaction

**Acceptance Criteria**:
- ✅ All transactions in batch processed
- ✅ No data loss or corruption
- ✅ Performance within acceptable limits (< 5 seconds)
- ✅ All transactions individually queryable

---

## 6. Phase 4: Error Handling Testing

### 6.1 Invalid Payload Testing

**Test ID**: UAT-ERROR-001  
**Objective**: Test system response to various invalid payloads

**Test Cases**:

| Test Case | Invalid Element | Expected Status | Expected Response |
|-----------|----------------|-----------------|-------------------|
| Missing tenant_id | Remove tenant_id field | 422 | Validation error for tenant_id |
| Invalid amount | Set base_amount to "invalid" | 422 | Validation error for base_amount |
| Missing checksum | Remove payload_checksum | 422 | Validation error for checksum |
| Invalid checksum | Use incorrect checksum | 422 | Checksum validation failed |
| Invalid timestamp | Use malformed date | 422 | Timestamp format error |

**Acceptance Criteria**:
- ✅ Appropriate error status codes returned
- ✅ Clear, descriptive error messages
- ✅ No data corruption or system crashes

---

### 6.2 Duplicate Transaction Testing

**Test ID**: UAT-ERROR-002  
**Objective**: Test duplicate transaction handling

**Test Steps**:
1. Submit a valid transaction
2. Submit the same transaction again (same transaction_id)
3. Verify system response

**Expected Response**:
```json
{
  "status": "error",
  "error_code": "DUPLICATE_TRANSACTION",
  "message": "Transaction ID already exists",
  "transaction_id": "txn-uat-duplicate"
}
```

**Acceptance Criteria**:
- ✅ Response status: 409 Conflict
- ✅ Clear duplicate error message
- ✅ Original transaction data unchanged

---

### 6.3 Network Timeout Testing

**Test ID**: UAT-ERROR-003  
**Objective**: Test handling of network timeouts and retries

**Test Steps**:
1. Configure client with shorter timeout (5 seconds)
2. Submit large batch transaction
3. Simulate network interruption
4. Implement retry logic with exponential backoff
5. Verify eventual successful submission

**Acceptance Criteria**:
- ✅ Graceful handling of timeouts
- ✅ Proper retry implementation
- ✅ Data integrity maintained
- ✅ No duplicate submissions

---

## 7. Phase 5: Performance Testing

### 7.1 Response Time Testing

**Test ID**: UAT-PERF-001  
**Objective**: Verify API response times meet requirements

**Performance Requirements**:
| Transaction Type | Expected Response Time |
|------------------|----------------------|
| Single transaction | < 500ms |
| Small batch (≤5) | < 1 second |
| Medium batch (6-10) | < 3 seconds |
| Large batch (11-20) | < 5 seconds |

**Test Process**:
1. Submit transactions of different sizes
2. Measure response times
3. Record results over multiple iterations
4. Verify consistency

**Acceptance Criteria**:
- ✅ Response times within specified limits
- ✅ Consistent performance across multiple tests
- ✅ No performance degradation over time

---

### 7.2 Concurrent Transaction Testing

**Test ID**: UAT-PERF-002  
**Objective**: Test concurrent transaction submissions

**Test Steps**:
1. Prepare 10 different transactions
2. Submit all transactions simultaneously
3. Verify all transactions are processed correctly
4. Check for any data conflicts or race conditions

**Acceptance Criteria**:
- ✅ All concurrent transactions processed
- ✅ No data corruption or conflicts
- ✅ Proper handling of simultaneous submissions

---

## 8. Phase 6: Integration Workflow Testing

### 8.1 Complete POS Integration Workflow

**Test ID**: UAT-WORKFLOW-001  
**Objective**: Test complete end-to-end POS integration workflow

**Workflow Steps**:
1. **Authentication**: Obtain and validate Sanctum bearer token
2. **Health Check**: Verify TSMS system availability
3. **Transaction Processing**: Submit various transaction types
4. **Status Checking**: Query transaction status
5. **Error Handling**: Handle various error scenarios
6. **Retry Logic**: Implement and test retry mechanisms

**Acceptance Criteria**:
- ✅ Complete workflow executes successfully
- ✅ All error scenarios handled appropriately
- ✅ Data consistency maintained throughout
- ✅ Proper logging and audit trails

---

### 8.2 Offline Mode Testing

**Test ID**: UAT-WORKFLOW-002  
**Objective**: Test POS behavior during TSMS unavailability

**Test Steps**:
1. Simulate TSMS system unavailability
2. Generate transactions during downtime
3. Store transactions locally
4. Restore TSMS connectivity
5. Submit queued transactions
6. Verify data integrity

**Acceptance Criteria**:
- ✅ Transactions stored locally during downtime
- ✅ Proper queue management
- ✅ Successful submission after connectivity restored
- ✅ No data loss or duplication

---

## 9. Phase 7: Security Testing

### 9.1 Authentication Security

**Test ID**: UAT-SEC-001  
**Objective**: Verify authentication security measures

**Test Cases**:
- Invalid token formats
- Expired tokens
- Token tampering attempts
- Missing authentication headers

**Acceptance Criteria**:
- ✅ All unauthorized requests properly rejected
- ✅ Secure error messages (no sensitive data exposure)
- ✅ Proper security logging

---

### 9.2 Data Validation Security

**Test ID**: UAT-SEC-002  
**Objective**: Test input validation and sanitization

**Test Cases**:
- SQL injection attempts in transaction data
- XSS attempts in string fields
- Invalid data types and formats
- Oversized payloads

**Acceptance Criteria**:
- ✅ All malicious input properly sanitized
- ✅ No security vulnerabilities exposed
- ✅ System remains stable under attack

---

## 10. Phase 8: Data Integrity Testing

### 10.1 Checksum Validation

**Test ID**: UAT-DATA-001  
**Objective**: Verify checksum calculation and validation

**Test Steps**:
1. Implement checksum calculation in POS system
2. Submit transactions with correct checksums
3. Submit transactions with incorrect checksums
4. Verify TSMS validation behavior

**Sample Checksum Calculation** (JavaScript):
```javascript
const crypto = require('crypto');

function computePayloadChecksum(obj) {
    const clone = JSON.parse(JSON.stringify(obj));
    if ('payload_checksum' in clone) {
        delete clone.payload_checksum;
    }
    const jsonString = JSON.stringify(clone);
    return crypto.createHash('sha256').update(jsonString).digest('hex');
}
```

**Acceptance Criteria**:
- ✅ Valid checksums accepted
- ✅ Invalid checksums rejected with clear error
- ✅ Consistent checksum calculation

---

### 10.2 Data Persistence Testing

**Test ID**: UAT-DATA-002  
**Objective**: Verify data is correctly stored and retrievable

**Test Steps**:
1. Submit various transaction types
2. Query transaction status multiple times
3. Verify data consistency across queries
4. Check that all fields are properly stored

**Acceptance Criteria**:
- ✅ All transaction data accurately stored
- ✅ Consistent data retrieval
- ✅ No data corruption or loss

---

## 11. UAT Acceptance Criteria Summary

### 11.1 Functional Requirements

- ✅ **Authentication**: Sanctum bearer token authentication working
- ✅ **Single Transactions**: All transaction types accepted and processed
- ✅ **Batch Transactions**: Batch processing working for various sizes
- ✅ **Error Handling**: Appropriate error responses for all scenarios
- ✅ **Data Validation**: Comprehensive input validation
- ✅ **Status Checking**: Transaction status queries working

### 11.2 Non-Functional Requirements

- ✅ **Performance**: Response times within specified limits
- ✅ **Security**: Authentication and input validation secure
- ✅ **Reliability**: System handles errors gracefully
- ✅ **Data Integrity**: Checksums and data validation working
- ✅ **Concurrency**: Multiple simultaneous transactions handled

### 11.3 Integration Requirements

- ✅ **POS Workflow**: Complete integration workflow tested
- ✅ **Offline Handling**: Offline mode and queue management working
- ✅ **Retry Logic**: Exponential backoff retry implemented
- ✅ **Monitoring**: Transaction status and health monitoring

---

## 12. UAT Sign-off

### 12.1 Testing Completion Checklist

**Core Functionality** (Must Pass):
- [ ] All Phase 1 tests passed (Connectivity)
- [ ] All Phase 2 tests passed (Single Transactions)
- [ ] All Phase 3 tests passed (Batch Transactions)
- [ ] All Phase 4 tests passed (Error Handling)

**Advanced Functionality** (Must Pass):
- [ ] All Phase 5 tests passed (Performance)
- [ ] All Phase 6 tests passed (Integration Workflow)
- [ ] All Phase 7 tests passed (Security)
- [ ] All Phase 8 tests passed (Data Integrity)

**Documentation** (Must Complete):
- [ ] UAT test results documented
- [ ] Known issues and workarounds documented
- [ ] POS integration code reviewed
- [ ] Production deployment plan prepared

### 12.2 Sign-off Requirements

**POS Provider Confirmation**:
- [ ] All UAT phases completed successfully
- [ ] POS system ready for production integration
- [ ] Support team trained on TSMS integration
- [ ] Monitoring and alerting configured

**TSMS Team Confirmation**:
- [ ] UAT results reviewed and approved
- [ ] Production credentials issued
- [ ] Monitoring systems configured for new POS provider
- [ ] Support procedures documented

### 12.3 Production Go-Live Checklist

- [ ] Production Sanctum bearer tokens issued
- [ ] Production endpoint URLs provided
- [ ] Rate limiting configured for production volumes
- [ ] Monitoring and alerting active
- [ ] Support escalation procedures in place
- [ ] Rollback plan documented

---

## 13. Appendices

### Appendix A: Sample Test Data

[Detailed test data sets for different transaction scenarios]

### Appendix B: Error Code Reference

[Complete list of TSMS error codes and descriptions]

### Appendix C: Performance Benchmarks

[Detailed performance requirements and measurement criteria]

### Appendix D: Security Guidelines

[Security best practices for POS terminal integration]

---

## 14. Support and Contact Information

### Technical Support
- **Email**: tsms-support@example.com
- **Phone**: +63-XXX-XXX-XXXX
- **Hours**: 24/7 for production issues

### Integration Support
- **Email**: tsms-integration@example.com
- **Documentation**: Available in `/docs/` directory
- **Status Page**: https://status.tsms.example.com

### Emergency Contact
- **Production Issues**: emergency@tsms.example.com
- **Security Issues**: security@tsms.example.com

---

**Document Version**: 1.0  
**Last Updated**: July 11, 2025  
**Next Review**: August 11, 2025  
**Approved By**: TSMS Development Team

---

## 12. Phase 9: Voided Transaction Testing (Future Enhancement)

### 12.1 Current Void Transaction Support

**Current Status**: ❌ **VERY LIMITED SUPPORT**

The TSMS system currently has **very limited support** for voided transactions. Analysis of the codebase reveals:

- ✅ **Zero-amount transactions** are technically allowed at the database model level
- ❌ **Negative base amounts** are explicitly rejected by validation rules (`min:0` in API requests)
- ❌ **Negative amounts validation** exists in the validation service for gross/net sales  
- ❌ **API validation** prevents negative `base_amount` via `ProcessTransactionRequest` and `TransactionRequest`
- ⚠️ **Workaround possible** via zero-amount transactions with negative adjustments (untested in production)

### 12.2 Current Transaction Processing Capabilities

**What the system CAN process**:
- ✅ Regular sale transactions with positive `base_amount`
- ✅ Zero-amount transactions (`base_amount: 0.0`)
- ✅ Transactions with negative adjustments (discounts, voids)
- ✅ Transactions with positive adjustments (service charges, tips)

**What the system CANNOT process**:
- ❌ Transactions with negative `base_amount` (rejected at API validation)
- ❌ Dedicated void transaction type (no `transaction_type` field)
- ❌ Refund transactions with proper linking to original transactions
- ❌ Void-specific business rule validation

### 12.3 Current Void Workaround

**Test ID**: UAT-VOID-001  
**Objective**: Test void transaction using current workaround method  
**Status**: ⚠️ **THEORETICAL ONLY** - Not tested in production

**Current Workaround Method** (Zero-amount + negative adjustment):
```json
{
  "submission_uuid": "12345678-1234-1234-1234-123456789012",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:30:00Z",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash-of-submission",
  "transaction": {
    "transaction_id": "VOID-TXN-001",
    "transaction_timestamp": "2025-07-11T10:30:00Z",
    "base_amount": 0.00,
    "payload_checksum": "computed-sha256-hash-of-transaction",
    "adjustments": [
      {
        "adjustment_type": "void_original_sale",
        "amount": -150.00
      }
    ]
  }
}
```

**Acceptance Criteria**:
- ✅ Zero base_amount transactions accepted at model level
- ❌ **API validation prevents most void scenarios** (negative amounts rejected)  
- ⚠️ Negative adjustments theoretically supported (not production tested)
- ❌ No void-specific validation or tracking
- ❌ No void transaction audit trail

### 12.4 Void Transaction Limitations

**Current System Limitations**:
- ❌ **No Transaction Type Field**: Cannot distinguish between SALE, VOID, REFUND
- ❌ **No Original Transaction Reference**: Cannot link void to original transaction  
- ❌ **No Void-Specific Validation**: No validation for void business rules
- ❌ **No Void Reporting**: No dedicated void transaction reporting
- ❌ **No Void API Endpoints**: No `/void` or `/refund` endpoints
- ❌ **API Validation Blocks Voids**: `base_amount` validation rule `min:0` prevents negative amounts

### 12.5 Technical Analysis Summary

**Database Level**:
- ✅ `transactions` table accepts `base_amount: 0.00`
- ✅ `adjustments` table can store negative amounts
- ❌ No `transaction_type` or `void_reference` fields

**API Level**:  
- ❌ `TransactionRequest::rules()` enforces `'base_amount' => 'required|numeric|min:0'`
- ❌ `ProcessTransactionRequest::rules()` enforces `'base_amount' => 'required|numeric|min:0'`
- ❌ Validation service rejects negative gross/net sales amounts

**Business Logic Level**:
- ❌ No void-specific processing workflows
- ❌ No void notification handling
- ❌ No void audit trail beyond standard transaction logs

### 12.6 Recommendations for Future Void Support

**Phase 1: Database Schema Updates**
- Add `transaction_type` enum field ('SALE', 'VOID', 'REFUND', 'ADJUSTMENT')
- Add `original_transaction_id` field for linking voids to original transactions
- Add `void_reason` field for audit purposes

**Phase 2: API Enhancements**  
- Create dedicated `/api/v1/transactions/{id}/void` endpoint
- Update validation rules to allow negative amounts for void transactions
- Add void-specific request validation classes

**Phase 3: Business Logic**
- Implement void-specific validation service  
- Add void transaction notifications
- Create void audit trail and reporting
- Add void transaction status tracking

**Phase 4: UAT Testing**
- Test void transaction creation and validation
- Test void notification workflows  
- Test void reporting and audit capabilities
- Test void transaction integration with POS terminals

### 12.4 Recommended Void Transaction Enhancements

**For Full Void Support, TSMS Should Add**:

#### **Database Schema Enhancements**:
```sql
-- Add to transactions table
ALTER TABLE transactions ADD COLUMN transaction_type ENUM('SALE', 'VOID', 'REFUND') DEFAULT 'SALE';
ALTER TABLE transactions ADD COLUMN void_amount DECIMAL(12,2) NULL;
ALTER TABLE transactions ADD COLUMN original_transaction_id VARCHAR(191) NULL;
ALTER TABLE transactions ADD COLUMN void_reason TEXT NULL;
ALTER TABLE transactions ADD COLUMN is_voided BOOLEAN DEFAULT FALSE;

-- Add indexes for void queries
CREATE INDEX idx_transactions_type ON transactions(transaction_type);
CREATE INDEX idx_transactions_original ON transactions(original_transaction_id);
CREATE INDEX idx_transactions_voided ON transactions(is_voided);
```

#### **Enhanced Payload Format**:
```json
{
  "submission_uuid": "12345678-1234-1234-1234-123456789012",
  "tenant_id": 999,
  "terminal_id": 888,
  "submission_timestamp": "2025-07-11T10:30:00Z",
  "transaction_count": 1,
  "payload_checksum": "computed-sha256-hash",
  "transaction": {
    "transaction_id": "VOID-TXN-001",
    "transaction_type": "VOID",
    "transaction_timestamp": "2025-07-11T10:30:00Z",
    "base_amount": 0.00,
    "void_amount": 150.00,
    "original_transaction_id": "TXN-ORIGINAL-001",
    "void_reason": "Customer requested refund",
    "payload_checksum": "computed-sha256-hash"
  }
}
```

#### **Recommended API Endpoints**:
```bash
# Void transaction endpoints
POST /api/v1/transactions/{id}/void
POST /api/v1/transactions/void
GET  /api/v1/transactions/{id}/void-history
GET  /api/v1/reports/voids

# Refund transaction endpoints  
POST /api/v1/transactions/{id}/refund
POST /api/v1/transactions/refund
```

### 12.5 Business Rules for Void Transactions

**When Full Void Support is Implemented**:

#### **Validation Rules**:
- ✅ Original transaction must exist and be valid
- ✅ Original transaction cannot already be voided
- ✅ Void amount cannot exceed original transaction amount
- ✅ Void must occur within allowed time window (e.g., same business day)
- ✅ Void reason must be provided for audit purposes

#### **Processing Rules**:
- ✅ Original transaction marked as `is_voided = true`
- ✅ Void transaction created with reference to original
- ✅ Financial impact properly calculated and recorded
- ✅ Audit trail maintained for void operations

### 12.6 UAT Testing for Enhanced Void Support

**When void enhancements are implemented, test these scenarios**:

#### **Test Cases**:
1. **Valid Void**: Void a valid, recent transaction
2. **Invalid Original**: Attempt to void non-existent transaction
3. **Already Voided**: Attempt to void already voided transaction
4. **Partial Void**: Void partial amount (if supported)
5. **Time Window**: Void transaction outside allowed time window
6. **Cross-Day Void**: Void transaction from previous business day
7. **Batch Void**: Void multiple transactions in batch

#### **Expected Responses**:
```json
// Successful void response
{
  "status": "success",
  "message": "Transaction voided successfully",
  "data": {
    "void_transaction_id": "VOID-TXN-001",
    "original_transaction_id": "TXN-ORIGINAL-001",
    "void_amount": 150.00,
    "void_timestamp": "2025-07-11T10:30:00Z",
    "void_reason": "Customer requested refund"
  }
}

// Failed void response
{
  "status": "error",
  "error_code": "VOID_NOT_ALLOWED",
  "message": "Original transaction is already voided",
  "data": {
    "original_transaction_id": "TXN-ORIGINAL-001",
    "void_status": "ALREADY_VOIDED"
  }
}
```

### 12.7 Current Void Implementation Guidance

**For POS Providers Using Current System**:

#### **Recommended Approach**:
1. **Use Zero Base Amount**: Set `base_amount: 0.00` for void transactions
2. **Negative Adjustments**: Use negative adjustment amounts to represent voided amounts
3. **Naming Convention**: Use clear transaction IDs (e.g., `VOID-{original-id}`)
4. **Local Tracking**: Maintain void-to-original mapping in POS system
5. **Documentation**: Document void reason in adjustment_type or customer_code

#### **Example Implementation**:
```javascript
// POS-side void transaction creation
function createVoidTransaction(originalTransaction, voidReason) {
    return {
        submission_uuid: generateUUID(),
        tenant_id: originalTransaction.tenant_id,
        terminal_id: originalTransaction.terminal_id,
        submission_timestamp: new Date().toISOString(),
        transaction_count: 1,
        transaction: {
            transaction_id: `VOID-${originalTransaction.transaction_id}`,
            transaction_timestamp: new Date().toISOString(),
            base_amount: 0.00,
            adjustments: [
                {
                    adjustment_type: `void_${voidReason}`,
                    amount: -originalTransaction.base_amount
                }
            ]
        }
    };
}
```

---
