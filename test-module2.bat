@echo off
echo TSMS Module 2 Test Runner
echo =========================
echo.

REM Change to the project directory
cd /d "%~dp0"

REM Create the test directory if it doesn't exist
if not exist "tests\Scripts" mkdir tests\Scripts

REM Create a log directory if it doesn't exist
if not exist "logs\tests" mkdir logs\tests

REM Set log file path with timestamp
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "LOGTIME=%dt:~0,8%-%dt:~8,6%"
set "LOG_FILE=logs\tests\module2-test-%LOGTIME%.log"

echo 📝 Logging test results to: %LOG_FILE%
echo.

REM Run the test script and capture output to log file
php tests\Scripts\RunModule2Tests.php > %LOG_FILE% 2>&1
type %LOG_FILE%

REM Check if the test script execution had errors
if %errorlevel% neq 0 (
    echo.
    echo ❌ Tests completed with errors. Check the log file for details: %LOG_FILE%
    echo    Common issues:
    echo    - Missing required classes/files
    echo    - PHP syntax errors
    echo    - Database connection issues
    echo.
) else (
    echo.
    echo ✅ Tests completed successfully. Log saved to: %LOG_FILE%
)

echo For more detailed tests, run: php artisan test --filter=Transaction
pause
