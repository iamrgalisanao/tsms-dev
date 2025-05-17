@echo off
echo TSMS POS Data Seeder
echo ====================
echo.

REM Change to the project directory
cd /d "%~dp0"

REM Run the database seeder
php artisan db:seed --class=PosSampleDataSeeder

echo.
echo Sample data seeding completed!
pause
