@echo off
REM Verify Module 2 Implementation
REM This script runs the PHPUnit test to verify Module 2 implementation

echo TSMS Module 2 Verification
echo ==========================
echo.

REM Change to the project directory
cd /d "%~dp0"

REM Create a logs directory if it doesn't exist
if not exist "logs\tests" mkdir logs\tests

REM Set log file path with timestamp
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "LOGTIME=%dt:~0,8%-%dt:~8,6%"
set "LOG_FILE=logs\tests\module2-verify-%LOGTIME%.log"

echo 📝 Logging test results to: %LOG_FILE%
echo.

echo 🚀 Running PHPUnit test for Module 2...
php artisan test --filter=Module2VerificationTest > %LOG_FILE% 2>&1
type %LOG_FILE%

REM Check test execution status
if %errorlevel% neq 0 (
    echo ❌ Tests completed with errors. See log for details: %LOG_FILE%
) else (
    echo ✅ Tests completed successfully. Log saved to: %LOG_FILE%
    
    REM Create a summary file
    set "SUMMARY_FILE=logs\tests\module2-summary-%LOGTIME%.txt"
    echo MODULE 2 VERIFICATION SUMMARY > %SUMMARY_FILE%
    findstr /c:"MODULE 2 VERIFICATION SUMMARY" /c:"===========" /c:"Transaction" /c:"POS Text" /c:"Job Queues" /c:"Error Handling" /c:"Implementation Status" /c:"EXCELLENT" /c:"GOOD" /c:"PARTIAL" /c:"INCOMPLETE" %LOG_FILE% >> %SUMMARY_FILE%
    
    echo 📊 Summary report saved to: %SUMMARY_FILE%
)
