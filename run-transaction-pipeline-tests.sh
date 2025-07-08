#!/bin/bash
echo "Running Transaction Pipeline Test Suite..."
echo

# Create test results directory
mkdir -p storage/test-results

# Run specific test suites
echo "========================================"
echo "Running Transaction Ingestion Tests..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Ingestion" --verbose
echo

echo "========================================"
echo "Running Transaction Queue Processing Tests..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Queue Processing" --verbose
echo

echo "========================================"
echo "Running Transaction Validation Tests..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Validation" --verbose
echo

echo "========================================"
echo "Running Transaction End-to-End Tests..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction End-to-End" --verbose
echo

echo "========================================"
echo "Running Transaction Performance Tests..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Performance" --verbose
echo

echo "========================================"
echo "Running Full Transaction Pipeline Suite..."
echo "========================================"
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Pipeline Tests" --coverage-html storage/coverage-html --coverage-text --verbose
echo

echo "========================================"
echo "Test Suite Complete!"
echo "========================================"
echo "Coverage report: storage/coverage-html/index.html"
echo "Test results: storage/test-results.html"
echo
