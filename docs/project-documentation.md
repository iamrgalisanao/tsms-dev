# TSMS (Transaction Management System) - Brownfield Architecture Documentation

## Table of Contents

1. [Project Overview](#project-overview)
2. [System Architecture](#system-architecture)
3. [Directory Structure](#directory-structure)
4. [Configuration Files](#configuration-files)
5. [Database Schema](#database-schema)
6. [API Endpoints](#api-endpoints)
7. [Core Models](#core-models)
8. [Services](#services)
9. [Jobs & Queues](#jobs--queues)
10. [Authentication & Security](#authentication--security)
11. [Integration Points](#integration-points)
12. [Technical Debt & Known Issues](#technical-debt--known-issues)
13. [Development Setup](#development-setup)

---

## Project Overview

### Purpose
TSMS (Transaction Management System) is a Laravel-based system that manages Point of Sale (POS) transaction processing for PITX (Paranaque Integrated Terminal Exchange). The system handles transaction ingestion, validation, processing, and forwarding to external web applications.

### Key Capabilities
- **Transaction Ingestion**: Multi-format transaction processing (single, batch, official TSMS format)
- **POS Terminal Management**: Terminal authentication, registration, and monitoring
- **Transaction Validation**: Real-time validation with checksum verification
- **Queue Processing**: Background job processing using Laravel Horizon
- **WebApp Forwarding**: Integration with external transaction processing systems
- **Circuit Breaker**: Fault tolerance for external integrations
- **Audit & Logging**: Comprehensive audit trails and system logging
- **Void & Refund**: Transaction reversal capabilities

### Current State Assessment
- **Status**: Production-ready brownfield system with active technical debt
- **PHP Version**: 8.2+
- **Laravel Version**: 11.x
- **Database**: MySQL with comprehensive migration structure
- **Queue System**: Redis-backed with Laravel Horizon
- **Authentication**: Laravel Sanctum for API tokens

---

## System Architecture

### High-Level Architecture
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   POS Terminal  │───▶│   TSMS API      │───▶│   WebApp        │
│   (Sanctum)     │    │   (Laravel)     │    │   (External)    │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │   Redis Queue   │
                       │   (Horizon)     │
                       └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │   MySQL DB      │
                       └─────────────────┘
```

### Technology Stack

| Component | Technology | Version | Notes |
|-----------|------------|---------|-------|
| Runtime | PHP | 8.2+ | Required for Laravel 11 |
| Framework | Laravel | 11.x | Latest LTS features |
| Database | MySQL | 8.0+ | Primary data store |
| Queue | Redis | 6.x+ | Background job processing |
| Authentication | Sanctum | 4.0+ | API token authentication |
| PDF Generation | DomPDF | 3.1+ | Report generation |
| Queue Monitoring | Horizon | 5.33+ | Queue dashboard |
| Permission System | Spatie Permission | 6.17+ | Role-based access |
| Excel Export | Maatwebsite Excel | 1.1+ | Data export functionality |

---

## Directory Structure

### Root Level Structure
```
tsms-dev/
├── app/                    # Application core
├── bootstrap/              # Framework bootstrap
├── config/                 # Configuration files
├── database/               # Migrations, seeds, factories
├── docs/                   # Documentation (this file)
├── public/                 # Web server entry point
├── resources/              # Views, assets, lang files
├── routes/                 # Route definitions
├── storage/                # File storage, logs, cache
├── tests/                  # Test suites
├── vendor/                 # Composer dependencies
├── .env.example           # Environment template
├── composer.json          # PHP dependencies
├── package.json           # Node.js dependencies
└── artisan                # CLI tool
```

### App Directory Structure
```
app/
├── Console/               # Artisan commands
├── Events/                # Event classes
├── Exceptions/            # Exception handlers
├── Exports/               # Excel export classes
├── Helpers/               # Helper functions
│   ├── LogHelper.php     # Logging utilities
│   └── BadgeHelper.php   # UI badge helpers
├── Http/                  # HTTP layer
│   ├── Controllers/       # Request handlers
│   │   ├── API/V1/       # API v1 controllers
│   │   ├── Admin/        # Admin controllers
│   │   ├── Auth/         # Authentication
│   │   └── Dashboard/    # Dashboard controllers
│   ├── Middleware/        # HTTP middleware
│   └── Requests/         # Form request validation
├── Jobs/                  # Queue job classes
├── Listeners/             # Event listeners
├── Models/                # Eloquent models
├── Notifications/         # Notification classes
├── Providers/             # Service providers
├── Services/              # Business logic services
└── Traits/               # Reusable traits
```

---

## Configuration Files

### Environment Configuration (.env.example)

```bash
# Application
APP_NAME=TSMS
APP_ENV=local
APP_KEY=base64:7V81VzeHumYviC54HgLP8Tb2gcIR9qcxW4XabSjRoWE=
APP_DEBUG=true
APP_TIMEZONE=Asia/Manila
APP_URL=http://stagingtsms.pitx.com.ph

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tsms_db
DB_USERNAME=tsms_user
DB_PASSWORD=StrongPassword123

# Queue & Cache
CACHE_DRIVER=file
QUEUE_CONNECTION=redis

# Authentication & Security
AUTH_GUARD=pos_api
SANCTUM_STATEFUL_DOMAINS=stagingtsms.pitx.com.ph,127.0.0.1
JWT_SECRET=oAfBtOidXERolYjHHI2W7gBQr4ql9WdUaDaEHkoYLT4WVfECW5IH4DPcuaUhq6Mv4aUbFmBQjE=

# WebApp Forwarding
WEBAPP_FORWARDING_ENABLED=true
WEBAPP_FORWARDING_ENDPOINT=http://stagingwebapp.pitx.com.ph/api/transactions/bulk
WEBAPP_FORWARDING_AUTH_TOKEN=tsms_7f8a2c1e_2025_ops_XYZ123

# Circuit Breaker
CIRCUIT_BREAKER_THRESHOLD=3
CIRCUIT_BREAKER_COOLDOWN=60
WEBAPP_CB_FAILURE_THRESHOLD=5
WEBAPP_CB_RECOVERY_TIMEOUT=10

# Rate Limiting
RATE_LIMIT_API_ATTEMPTS=60
RATE_LIMIT_API_DECAY_MINUTES=1
```

### Composer Dependencies (composer.json)
```json
{
  "require": {
    "php": "^8.2",
    "laravel/framework": "^11.0",
    "laravel/sanctum": "^4.0",
    "laravel/horizon": "^5.33",
    "barryvdh/laravel-dompdf": "^3.1",
    "maatwebsite/excel": "^1.1",
    "spatie/laravel-permission": "^6.17",
    "predis/predis": "^3.0",
    "tymon/jwt-auth": "^2.2"
  }
}
```

---

## Database Schema

### Core Tables

#### transactions
```sql
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `terminal_id` bigint unsigned NOT NULL,
  `transaction_id` varchar(191) NOT NULL UNIQUE,
  `hardware_id` varchar(191) NOT NULL,
  `transaction_timestamp` timestamp NOT NULL,
  `base_amount` decimal(12,2) NOT NULL,
  `customer_code` varchar(191) DEFAULT NULL,
  `payload_checksum` varchar(191) NOT NULL,
  `validation_status` enum('PENDING','VALID','INVALID') DEFAULT 'PENDING',
  `submission_uuid` varchar(191) DEFAULT NULL,
  `submission_timestamp` timestamp NULL DEFAULT NULL,
  `refund_status` varchar(50) DEFAULT NULL,
  `refund_amount` decimal(12,2) DEFAULT NULL,
  `refund_reason` text,
  `refund_reference_id` varchar(191) DEFAULT NULL,
  `refund_processed_at` timestamp NULL DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `void_reason` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`)
);
```

#### pos_terminals
```sql
CREATE TABLE `pos_terminals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `terminal_uid` varchar(191) NOT NULL,
  `serial_number` varchar(191) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `callback_url` varchar(500) DEFAULT NULL,
  `notifications_enabled` tinyint(1) DEFAULT 1,
  `api_token` varchar(80) DEFAULT NULL,
  `abilities` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial_number` (`serial_number`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
);
```

#### Key Migration Files
- `2025_07_04_000004_create_transactions_table.php` - Core transaction structure
- `2025_08_07_000001_add_voided_fields_to_transactions_table.php` - Void functionality
- `2025_08_04_000000_add_refund_fields_to_transactions_table.php` - Refund capabilities
- `2025_07_09_153611_add_sanctum_fields_to_pos_terminals_table.php` - API authentication

---

## API Endpoints

### Authentication Endpoints
```php
// Terminal Authentication (Public)
POST /api/v1/auth/terminal        // Authenticate terminal, get token
POST /api/v1/auth/refresh         // Refresh token (requires auth)
GET  /api/v1/auth/me             // Get terminal info (requires auth)
POST /api/v1/heartbeat           // Terminal heartbeat (requires heartbeat ability)
```

### Transaction Endpoints
```php
// Transaction Management (Sanctum Protected)
POST /api/v1/transactions                    // Create single transaction
POST /api/v1/transactions/batch             // Batch transaction creation
POST /api/v1/transactions/official          // Official TSMS format submission
GET  /api/v1/transactions/{id}/status       // Get transaction status
POST /api/v1/transactions/{id}/refund       // Process refund
POST /api/v1/transactions/{id}/void         // Void transaction (POS-initiated)

// Legacy Endpoints (Rate Limited, Backward Compatibility)
POST /api/v1/transactions        // Legacy transaction creation
GET  /api/v1/transactions/{id}/status  // Legacy status check
```

### System Endpoints
```php
// Health & Monitoring
GET  /api/v1/health              // System health check
GET  /api/v1/retry-history       // Retry history dashboard
POST /api/v1/retry-history/{id}/retry  // Manual retry

// Development/Testing
POST /api/v1/test-parser         // Test transaction parser
GET  /api/v1/recent-test-transactions  // Recent test data
```

### Request/Response Formats

#### Transaction Creation Request
```json
{
  "tenant_id": "123",
  "serial_number": "TERM12345",
  "transaction_id": "TXN-2025-001",
  "hardware_id": "HW-001",
  "transaction_timestamp": "2025-08-11T10:00:00Z",
  "base_amount": 1500.00,
  "submission_uuid": "uuid-v4-string",
  "submission_timestamp": "2025-08-11T10:00:00Z"
}
```

#### Transaction Response
```json
{
  "success": true,
  "message": "Transaction queued for processing",
  "data": {
    "transaction_id": "TXN-2025-001",
    "serial_number": "TERM12345",
    "status": "queued",
    "job_status": "PENDING",
    "timestamp": "2025-08-11T10:00:00.000000Z"
  }
}
```

#### Void Transaction Request (POS-initiated)
```json
{
  "transaction_id": "TXN-2025-001",
  "void_reason": "Customer request",
  "payload_checksum": "sha256-hash-of-payload"
}
```

---

## Core Models

### Transaction Model (`app/Models/Transaction.php`)
```php
class Transaction extends Model
{
    // Validation Status Constants
    const VALIDATION_STATUS_VALID   = 'VALID';
    const VALIDATION_STATUS_PENDING = 'PENDING';
    const VALIDATION_STATUS_FAILED  = 'FAILED';
    
    // Job Status Constants
    const JOB_STATUS_QUEUED = 'QUEUED';
    const JOB_STATUS_PROCESSING = 'PROCESSING';
    const JOB_STATUS_COMPLETED = 'COMPLETED';
    const JOB_STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'tenant_id', 'terminal_id', 'transaction_id',
        'hardware_id', 'transaction_timestamp', 'base_amount',
        'customer_code', 'payload_checksum', 'validation_status',
        'submission_uuid', 'submission_timestamp',
        'refund_status', 'refund_amount', 'refund_reason',
        'refund_reference_id', 'refund_processed_at',
        'voided_at', 'void_reason'
    ];

    // Key Methods
    public function void($reason = null)          // Mark transaction as voided
    public function isVoided(): bool              // Check if voided
    public function isRefunded(): bool            // Check if refunded
    public function canRefund(): bool             // Check refund eligibility
    public function isEligibleForWebappForward(): bool  // Check forwarding eligibility
    
    // Relationships
    public function terminal()                    // BelongsTo PosTerminal
    public function tenant()                      // BelongsTo Tenant
    public function adjustments()                 // HasMany TransactionAdjustment
    public function taxes()                       // HasMany TransactionTax
    public function jobs()                        // HasMany TransactionJob
    public function validations()                 // HasMany TransactionValidation
    public function webappForward()               // HasOne WebappTransactionForward
}
```

### PosTerminal Model (`app/Models/PosTerminal.php`)
- Sanctum authentication capabilities
- Terminal status tracking
- Notification settings management
- Tenant relationship

### Key Supporting Models
- `Tenant`: Multi-tenancy support
- `TransactionAdjustment`: Transaction modifications
- `TransactionTax`: Tax calculations
- `TransactionJob`: Job processing tracking
- `TransactionValidation`: Validation history
- `WebappTransactionForward`: External system integration
- `AuditLog`: System audit trails
- `SystemLog`: Application logging

---

## Services

### TransactionController (`app/Http/Controllers/API/V1/TransactionController.php`)

**Primary transaction handling controller with extensive functionality:**

#### Key Methods:
- `store(Request $request)` - Single transaction ingestion
- `batchStore(Request $request)` - Batch transaction processing
- `storeOfficial(Request $request)` - Official TSMS format processing
- `void(Request $request, $transaction_id)` - Internal void processing
- `voidFromPOS(Request $request, $transaction_id)` - POS-initiated void with Sanctum auth
- `refund(Request $request, $id)` - Transaction refund processing
- `status($id)` - Transaction status retrieval

#### Technical Debt Notes:
- **Mixed Authentication**: Some endpoints use Sanctum, others use legacy tokens
- **Validation Inconsistency**: Different validation patterns across methods
- **Logging Redundancy**: Multiple audit log creation attempts with error handling
- **Error Handling**: Inconsistent error response formats

### TransactionService (`app/Services/TransactionService.php`)
```php
class TransactionService
{
    public function processRefund(Transaction $transaction, array $refundData): Transaction
    public function store(array $payload): Transaction
    
    // Protected helpers
    protected function logTransactionHistory($transaction, $status, $message = null)
    protected function updateStatus($transaction, $status, $message = null)
    protected function updateTransactionStatus($transaction, $status, $jobStatus = null)
}
```

### Key Service Classes:
- **TransactionValidationService**: Transaction validation logic
- **WebAppForwardingService**: External system integration
- **PayloadChecksumService**: SHA-256 payload validation
- **CircuitBreakerService**: Fault tolerance management
- **NotificationService**: Terminal notification handling
- **RetryHistoryService**: Failed job retry management

---

## Jobs & Queues

### ProcessTransactionJob (`app/Jobs/ProcessTransactionJob.php`)
```php
class ProcessTransactionJob implements ShouldQueue
{
    protected $transaction;
    protected $maxAttempts = 3;
    
    public function handle(TransactionValidationService $validationService)
    {
        // 1. Create TransactionJob tracking record
        // 2. Create TransactionValidation record  
        // 3. Run validation via TransactionValidationService
        // 4. Update job and validation records based on results
        // 5. Handle failures with retry logic
    }
}
```

#### Queue Configuration:
- **Queue Driver**: Redis
- **Queue Monitor**: Laravel Horizon
- **Max Attempts**: 3 retries per job
- **Retry Delay**: Exponential backoff
- **Failed Job Handling**: Stored in failed_jobs table

### Queue Processing Flow:
1. Transaction submitted via API
2. `ProcessTransactionJob` dispatched to Redis queue
3. Horizon worker picks up job
4. `TransactionValidationService` validates transaction
5. Results stored in `TransactionValidation` table
6. Success: Transaction marked VALID, eligible for webapp forwarding
7. Failure: Job retried up to 3 times, then marked FAILED

---

## Authentication & Security

### Sanctum API Authentication
```php
// Terminal authentication flow:
POST /api/v1/auth/terminal
{
  "terminal_uid": "TERM12345",
  "serial_number": "SN-12345",
  "credentials": "encrypted_data"
}

// Response includes bearer token with abilities:
{
  "token": "1|abcdef...",
  "abilities": ["transaction:create", "transaction:read", "heartbeat:send"]
}
```

### Security Features:
- **SHA-256 Payload Checksums**: Integrity verification for POS requests
- **Rate Limiting**: Configurable API rate limits
- **Circuit Breaker**: External service fault tolerance
- **Audit Logging**: Comprehensive security event tracking
- **Token Expiration**: Configurable token lifetimes
- **IP Restrictions**: Terminal-specific IP validation (when configured)

### Token Abilities:
- `transaction:create` - Create transactions
- `transaction:read` - Read transaction status
- `heartbeat:send` - Send heartbeat signals

---

## Integration Points

### WebApp Forwarding Service
```php
// Configuration via .env
WEBAPP_FORWARDING_ENABLED=true
WEBAPP_FORWARDING_ENDPOINT=http://stagingwebapp.pitx.com.ph/api/transactions/bulk
WEBAPP_FORWARDING_AUTH_TOKEN=tsms_7f8a2c1e_2025_ops_XYZ123
WEBAPP_FORWARDING_BATCH_SIZE=50
```

#### Forwarding Flow:
1. Transaction validated successfully (VALID status)
2. Eligible transactions batched (up to 50 per batch)
3. Forwarded to external webapp endpoint
4. Response tracked in `webapp_transaction_forwards` table
5. Circuit breaker handles failures
6. Failed forwards queued for retry

### External Dependencies:
- **WebApp Transaction Processor**: Receives forwarded transactions
- **POS Terminal Systems**: Submit transactions via API
- **Redis Server**: Queue and cache management
- **MySQL Database**: Primary data persistence

### Webhook Notifications:
```php
// Terminal callback notifications
POST {terminal.callback_url}
{
  "transaction_id": "TXN-2025-001",
  "validation_result": "success|failed",
  "validation_errors": [],
  "timestamp": "2025-08-11T10:00:00Z"
}
```

---

## Technical Debt & Known Issues

### Critical Technical Debt:

#### 1. Authentication Pattern Inconsistency
- **Issue**: Mixed use of Sanctum tokens and legacy authentication
- **Location**: `routes/api.php` - Multiple auth patterns for same endpoints
- **Impact**: Security vulnerabilities and maintenance complexity
- **Files**: `routes/api.php` lines 40-75

#### 2. Validation Service Duplication
- **Issue**: Multiple validation methods with different patterns
- **Location**: `TransactionController.php` - Inconsistent validation across methods
- **Impact**: Bug-prone validation logic
- **Files**: `TransactionController.php` methods `store()`, `storeOfficial()`, `voidFromPOS()`

#### 3. Error Handling Inconsistency
- **Issue**: Different error response formats across endpoints
- **Location**: Throughout API controllers
- **Impact**: Poor client integration experience
- **Example**: Some return `success: false`, others return HTTP error codes only

#### 4. Logging Redundancy
- **Issue**: Multiple audit log creation attempts with duplicate error handling
- **Location**: `TransactionController.php` lines 500-600
- **Impact**: Performance overhead and log noise

#### 5. Database Query Inefficiency
- **Issue**: N+1 query problems in transaction listing
- **Location**: Dashboard and reporting controllers
- **Impact**: Performance degradation with large datasets

### Known Constraints:

#### 1. Laravel Framework Constraints
- **Must** maintain Laravel 11.x compatibility
- **Cannot** modify core framework files
- **Must** follow Laravel conventions for service providers

#### 2. Database Constraints
- **Cannot** modify existing table structures without migrations
- **Must** maintain referential integrity for tenant relationships
- **Cannot** change primary key structures

#### 3. Queue System Constraints
- **Must** maintain Redis compatibility
- **Cannot** change job serialization without data loss
- **Must** handle job failures gracefully

### Workarounds in Production:

#### 1. Terminal Authentication Fallback
- **Issue**: Some terminals use legacy authentication
- **Workaround**: Dual authentication support in middleware
- **Code**: `app/Http/Middleware/` - Multiple auth guards

#### 2. Transaction ID Collision Handling
- **Issue**: Rare duplicate transaction IDs across tenants
- **Workaround**: Composite unique key (transaction_id + terminal_id)
- **Code**: Database migrations with composite indexes

---

## Development Setup

### Prerequisites:
- PHP 8.2+
- Composer
- Node.js & npm
- MySQL 8.0+
- Redis 6.x+

### Installation Steps:
```bash
# 1. Clone repository
git clone [repository-url]
cd tsms-dev

# 2. Install PHP dependencies
composer install

# 3. Install Node.js dependencies  
npm install

# 4. Environment setup
cp .env.example .env
php artisan key:generate

# 5. Database setup
php artisan migrate
php artisan db:seed

# 6. Queue setup
php artisan horizon:install
php artisan horizon:publish

# 7. Storage linking
php artisan storage:link
```

### Development Commands:
```bash
# Start development server
php artisan serve

# Start queue workers
php artisan horizon

# Run tests
php artisan test

# Clear caches
php artisan optimize:clear

# Generate IDE helpers
php artisan ide-helper:generate
```

### Testing Endpoints:
```bash
# Health check
curl http://tsms-dev.test/api/v1/health

# Test transaction creation
curl -X POST http://tsms-dev.test/api/v1/transactions \
  -H "Content-Type: application/json" \
  -d '{"tenant_id":"1","serial_number":"TEST001","transaction_id":"TEST-001","hardware_id":"HW001","transaction_timestamp":"2025-08-11T10:00:00Z","base_amount":1500.00,"submission_uuid":"test-uuid","submission_timestamp":"2025-08-11T10:00:00Z"}'
```

### Key Development Files:
- `artisan` - Laravel CLI tool
- `composer.json` - PHP dependency management
- `package.json` - Node.js dependencies
- `.env.example` - Environment configuration template
- `phpunit.xml` - Test configuration
- `webpack.mix.js` - Asset compilation
- `vite.config.js` - Modern asset compilation

---

## Useful Commands and Scripts

### Frequently Used Artisan Commands:
```bash
php artisan migrate           # Run database migrations
php artisan queue:work        # Start single queue worker
php artisan horizon           # Start Horizon queue dashboard
php artisan schedule:run      # Run scheduled commands
php artisan cache:clear       # Clear application cache
php artisan config:cache      # Cache configuration
php artisan route:list        # List all registered routes
```

### Custom Scripts:
- `seed-pos-data.sh` - Seed POS terminal test data
- `run-transaction-pipeline-tests.sh` - Run transaction processing tests
- `validate-integration.php` - Integration validation script

### Debugging Commands:
```bash
# Transaction status check
php artisan tinker
> Transaction::where('transaction_id', 'TXN-001')->first()

# Queue job inspection
php artisan horizon:snapshot

# Log monitoring
tail -f storage/logs/laravel.log
```

This documentation represents the current state of the TSMS codebase as of August 2025, including all technical debt, workarounds, and architectural decisions that affect day-to-day development and maintenance.
