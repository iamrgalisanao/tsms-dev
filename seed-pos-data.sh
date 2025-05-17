#!/bin/bash

# Seed POS sample data
# This script seeds the POS provider, terminal, and statistics tables with sample data

echo "TSMS POS Data Seeder"
echo "===================="
echo

# Change to the project directory
cd "$(dirname "$0")"

# Run the database seeder
php artisan db:seed --class=PosSampleDataSeeder

echo
echo "Sample data seeding completed!"
