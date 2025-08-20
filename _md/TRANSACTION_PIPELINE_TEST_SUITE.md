# Transaction Pipeline Test Suite Documentation

## Overview

This comprehensive test suite provides automated testing for the entire transaction processing pipeline in the TSMS (Terminal Sales Management System). The tests cover all stages from transaction ingestion through validation, queue processing, and final completion.

## Test Structure

### Test Categories

1. **Transaction Ingestion Tests** (`TransactionIngestionTest.php`)

    - API endpoint testing
    - Authentication and authorization
    - Payload validation
    - Error handling
    - Rate limiting
    - Batch processing

2. **Transaction Queue Processing Tests** (`TransactionQueueProcessingTest.php`)

    - Job dispatch and execution
    - Queue worker functionality
    - Retry mechanisms
    - Failure handling
    - Concurrency testing

3. **Transaction Validation Tests** (`TransactionValidationTest.php`)

    - Business rule validation
    - Data integrity checks
    - Operating hours validation
    - Amount limits
    - Terminal status validation
    - Duplicate prevention

4. **Transaction End-to-End Tests** (`TransactionEndToEndTest.php`)

    - Complete pipeline testing
    - Audit trail verification
    - System recovery scenarios
    - Performance monitoring
    - Data consistency

5. **Transaction Performance Tests** (`TransactionPerformanceTest.php`)
    - Load testing
    - Throughput measurement
    - Memory usage monitoring
    - Database performance
    - Cache efficiency

## Test Files

### 1. TransactionIngestionTest.php

**Purpose**: Tests the API endpoints that receive transactions from POS terminals.

**Key Test Cases**:

-   `can_receive_single_transaction_successfully()`: Validates successful single transaction ingestion
-   `can_receive_batch_transactions_successfully()`: Tests batch transaction processing
-   `rejects_invalid_authentication()`: Ensures proper authentication is required
-   `validates_required_fields()`: Checks field validation
-   `prevents_duplicate_transaction_ids()`: Prevents duplicate transactions
-   `logs_transaction_ingestion_events()`: Verifies audit logging
-   `handles_malformed_json()`: Tests error handling for invalid JSON
-   `enforces_rate_limiting()`: Ensures rate limiting works properly

### 2. TransactionQueueProcessingTest.php

**Purpose**: Tests the background job processing system.

**Key Test Cases**:

-   `processes_transaction_job_successfully()`: Verifies successful job processing
-   `dispatches_job_to_queue()`: Tests job dispatch to queue
-   `handles_validation_errors_gracefully()`: Error handling in job processing
-   `retries_failed_jobs()`: Tests retry mechanisms
-   `processes_multiple_transactions_in_batch()`: Batch processing capability
-   `handles_queue_worker_failures()`: Worker failure recovery
-   `processes_high_priority_transactions()`: Priority queue handling
-   `validates_transaction_during_processing()`: Validation integration

### 3. TransactionValidationTest.php

**Purpose**: Tests the business logic validation engine.

**Key Test Cases**:

-   `validates_valid_transaction()`: Confirms valid transactions pass
-   `validates_operating_hours()`: Business hours validation
-   `validates_negative_amounts()`: Amount validation rules
-   `validates_terminal_status()`: Terminal status checks
-   `validates_maximum_amount_limits()`: Upper limit validation
-   `validates_minimum_amount_limits()`: Lower limit validation
-   `validates_duplicate_transaction_ids()`: Duplicate prevention
-   `validates_transaction_items_consistency()`: Item total validation
-   `validates_business_rules()`: Custom business rule validation

### 4. TransactionEndToEndTest.php

**Purpose**: Tests complete transaction workflows from start to finish.

**Key Test Cases**:

-   `completes_full_transaction_lifecycle()`: Full pipeline test
-   `handles_invalid_transaction_end_to_end()`: Invalid transaction flow
-   `processes_batch_transactions_end_to_end()`: Batch processing flow
-   `maintains_data_integrity_during_concurrent_processing()`: Concurrency testing
-   `handles_system_recovery_scenarios()`: Recovery testing
-   `generates_comprehensive_audit_trail()`: Audit trail verification
-   `measures_performance_metrics()`: Performance monitoring
-   `handles_edge_cases_and_boundary_conditions()`: Edge case testing

### 5. TransactionPerformanceTest.php

**Purpose**: Tests system performance under various load conditions.

**Key Test Cases**:

-   `processes_single_transaction_within_time_limit()`: Single transaction speed
-   `processes_batch_of_transactions_efficiently()`: Batch efficiency
-   `handles_high_volume_concurrent_transactions()`: High volume testing
-   `maintains_database_performance_under_load()`: Database performance
-   `handles_memory_usage_efficiently()`: Memory management
-   `validates_transaction_throughput()`: Throughput measurement
-   `handles_peak_load_scenarios()`: Peak load testing
-   `monitors_system_resource_usage()`: Resource monitoring

## Running the Tests

### Prerequisites

1. **PHP 8.1+** with required extensions
2. **Composer** dependencies installed
3. **Database** configured for testing
4. **Queue** system configured (Redis/Database)
5. **Environment** variables set for testing

### Quick Start

```bash
# Run all transaction pipeline tests
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml

# Run specific test suite
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Ingestion"

# Run with coverage
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --coverage-html storage/coverage-html
```

### Using the Test Runners

**Windows (PowerShell/CMD)**:

```cmd
run-transaction-pipeline-tests.bat
```

**Linux/macOS**:

```bash
chmod +x run-transaction-pipeline-tests.sh
./run-transaction-pipeline-tests.sh
```

### Individual Test Suites

```bash
# Ingestion tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Ingestion"

# Queue processing tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Queue Processing"

# Validation tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Validation"

# End-to-end tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction End-to-End"

# Performance tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Performance"
```

## Test Configuration

### Environment Setup

Create a `.env.testing` file with:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tsms_testing
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
CACHE_DRIVER=array
SESSION_DRIVER=array

# Test-specific settings
TRANSACTION_PROCESSING_TIMEOUT=30
MAX_TRANSACTION_AMOUNT=50000
MIN_TRANSACTION_AMOUNT=1
OPERATING_HOURS_START=6
OPERATING_HOURS_END=22
```

### Database Setup

```bash
# Create test database
mysql -u root -p -e "CREATE DATABASE tsms_testing;"

# Run migrations
php artisan migrate --env=testing

# Seed test data if needed
php artisan db:seed --env=testing
```

## Test Data and Factories

### Available Factories

-   `TransactionFactory`: Creates test transactions
-   `PosTerminalFactory`: Creates test terminals
-   `TenantFactory`: Creates test tenants
-   `TransactionJobFactory`: Creates test job records
-   `TransactionValidationFactory`: Creates test validation records

### Test Data Patterns

```php
// Valid transaction
$transaction = Transaction::factory()->create([
    'terminal_id' => $terminal->id,
    'customer_code' => $tenant->customer_code,
    'base_amount' => 100.00,
    'validation_status' => 'PENDING'
]);

// Invalid transaction (negative amount)
$invalidTransaction = Transaction::factory()->create([
    'base_amount' => -50.00
]);

// High-value transaction
$highValueTransaction = Transaction::factory()->create([
    'base_amount' => 10000.00
]);
```

## Performance Benchmarks

### Expected Performance Metrics

-   **Single Transaction Processing**: < 500ms
-   **Batch Processing (10 transactions)**: < 3 seconds
-   **High Volume (50 transactions)**: < 15 seconds
-   **Peak Load (100 transactions)**: < 30 seconds
-   **Throughput**: > 10 transactions/second
-   **Memory Usage**: < 100MB for 100 transactions

### Performance Test Scenarios

1. **Single Transaction**: Measures individual transaction processing time
2. **Batch Processing**: Tests efficiency of processing multiple transactions
3. **High Volume**: Simulates high transaction volume scenarios
4. **Peak Load**: Tests system under peak load conditions
5. **Memory Management**: Monitors memory usage during processing
6. **Database Performance**: Measures database query efficiency
7. **Cache Performance**: Tests caching effectiveness

## Monitoring and Reporting

### Test Reports

After running tests, the following reports are generated:

-   **Coverage Report**: `storage/coverage-html/index.html`
-   **Test Results**: `storage/test-results.html`
-   **JUnit XML**: `storage/test-results.xml`
-   **Text Coverage**: `storage/coverage.txt`

### Continuous Integration

Example GitHub Actions workflow:

```yaml
name: Transaction Pipeline Tests

on: [push, pull_request]

jobs:
    test:
        runs-on: ubuntu-latest

        steps:
            - uses: actions/checkout@v2

            - name: Setup PHP
              uses: shivammathur/setup-php@v2
              with:
                  php-version: "8.1"
                  extensions: mbstring, xml, ctype, iconv, intl, pdo_mysql

            - name: Install dependencies
              run: composer install --prefer-dist --no-progress

            - name: Run tests
              run: |
                  php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --coverage-clover coverage.xml

            - name: Upload coverage
              uses: codecov/codecov-action@v2
              with:
                  file: ./coverage.xml
```

## Troubleshooting

### Common Issues

1. **Database Connection Errors**

    - Ensure test database exists
    - Check database credentials in `.env.testing`
    - Verify migrations are up to date

2. **Queue Processing Issues**

    - Check queue configuration
    - Ensure queue workers are not running during tests
    - Verify job classes are properly loaded

3. **Memory Issues**

    - Increase PHP memory limit: `ini_set('memory_limit', '512M')`
    - Clear objects after use in tests
    - Use database transactions for cleanup

4. **Authentication Failures**
    - Verify JWT configuration
    - Check terminal authentication setup
    - Ensure proper test data creation

### Debug Commands

```bash
# Run single test with debug output
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --filter testName --debug

# Run with verbose output
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --verbose

# Check test configuration
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --list-tests
```

## Contributing

### Adding New Tests

1. Create test file in `tests/Feature/TransactionPipeline/`
2. Extend `TestCase` and use `RefreshDatabase` trait
3. Follow naming convention: `TransactionFeatureTest.php`
4. Add test methods with `@test` annotation or `test_` prefix
5. Update test suite configuration if needed

### Test Guidelines

-   Use descriptive test method names
-   Include setup and teardown as needed
-   Mock external dependencies
-   Test both success and failure scenarios
-   Include performance assertions where relevant
-   Document complex test scenarios

### Code Coverage

Aim for:

-   **Line Coverage**: > 90%
-   **Branch Coverage**: > 85%
-   **Method Coverage**: > 95%

## Security Considerations

### Test Data Security

-   Never use real customer data in tests
-   Use factories for generating test data
-   Ensure test databases are isolated
-   Clean up test data after runs

### API Security Testing

-   Test authentication requirements
-   Verify authorization checks
-   Test rate limiting
-   Validate input sanitization
-   Check for injection vulnerabilities

## Integration with Existing Tests

This test suite complements the existing Laravel test structure:

```
tests/
├── Feature/
│   ├── TransactionPipeline/          # New comprehensive tests
│   │   ├── TransactionIngestionTest.php
│   │   ├── TransactionQueueProcessingTest.php
│   │   ├── TransactionValidationTest.php
│   │   ├── TransactionEndToEndTest.php
│   │   └── TransactionPerformanceTest.php
│   ├── TransactionProcessingTest.php  # Existing tests
│   ├── TransactionStatusTest.php      # Existing tests
│   └── ...
├── Unit/
└── ...
```

## Best Practices

1. **Test Isolation**: Each test should be independent
2. **Data Cleanup**: Use database transactions or cleanup methods
3. **Mock External Services**: Don't rely on external APIs
4. **Performance Awareness**: Monitor test execution time
5. **Documentation**: Keep test documentation updated
6. **Continuous Testing**: Run tests on every code change

This comprehensive test suite ensures the transaction processing pipeline is robust, performant, and reliable under various conditions.
