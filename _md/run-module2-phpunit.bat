@echo off
echo TSMS Module 2 PHPUnit Test Runner
echo =================================
echo.

REM Change to the project directory
cd /d "%~dp0"

REM Create a logs directory if it doesn't exist
if not exist "logs\tests" mkdir logs\tests

REM Set log file path with timestamp
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "LOGTIME=%dt:~0,8%-%dt:~8,6%"
set "LOG_FILE=logs\tests\module2-phpunit-%LOGTIME%.log"

echo 📝 Logging test results to: %LOG_FILE%
echo.

echo 🚀 Running PHPUnit test for Module 2...
php artisan test --filter=Module2Test --verbose > %LOG_FILE% 2>&1
type %LOG_FILE%

REM Check test execution status
if %errorlevel% neq 0 (
    echo ❌ Tests completed with errors. See log for details: %LOG_FILE%
) else (
    echo ✅ Tests completed successfully. Log saved to: %LOG_FILE%
)

REM Check if test report was generated
if exist "storage\app\module2_test_report.md" (
    echo.
    echo 📊 Test report generated: storage\app\module2_test_report.md
    echo Report content:
    echo ---------------
    type storage\app\module2_test_report.md
    
    REM Copy to logs directory for easier access
    copy storage\app\module2_test_report.md logs\tests\module2-report-%LOGTIME%.md
    echo.
    echo 📝 Report copied to: logs\tests\module2-report-%LOGTIME%.md
)

echo.
echo For more detailed module testing, you can also run:
echo php artisan test --filter=Transaction
echo php artisan test --filter=Retry
echo php artisan test --filter=Integration
pause
