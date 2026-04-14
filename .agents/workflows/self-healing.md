# Workflow: Self-Healing Protocol

This workflow defines the autonomous recovery loop for the TSMS AI Agent. It must be triggered after any code modification to ensure the integrity of the system and project standards.

## Protocol: Plan-Execute-Verify-Repair (PEVR)

### 1. Sentinel Trigger (Post-Execution)
Immediately after a `replace_file_content` or `multi_replace_file_content` call, the agent MUST run a localized validation check.
- **PHP Files**: Run `php -l {path/to/file}`.
- **JS/React Files**: Run `npm run build` or a specific test file `npm test {path/to/test}`.
- **Database/Migrations**: Run `php artisan migrate:pretend`.

### 2. Diagnosis (On Failure)
If the sentinel returns a non-zero exit code:
- **DO NOT** ask the user "how to fix this."
- **Capture**: Read the entire stdout/stderr output.
- **Analyze**: Identify the specific line number and error type (e.g., Syntax Error, Type Error, Undefined Variable).
- **Match**: Check `findings.md` or `self-healing.log` for recurring pattern matches.

### 3. Autonomous Repair
Apply the "Smallest Viable Fix" (SVF) pattern:
- **Rule**: Only touch the code directly causing the error.
- **Retry Limit**: Attempt a recursive repair up to **3 times**.
- **Log**: Every attempt must be documented in `self-healing.log`.

### 4. Circuit Breaker (Human-in-the-Loop)
If the error persists after 3 attempts:
- **Freeze**: Stop all automated modifications to the file.
- **Report**: Present the user with a summary of the 3 failed attempts, the current error log, and your hypothesis on why the autonomous fix failed.
- **Wait**: Explicitly request human guidance.

## Documentation Sync
After a successful repair, the agent must run the `/documentation-sync` workflow to ensure that any architectural drift caused by the fix is documented.
