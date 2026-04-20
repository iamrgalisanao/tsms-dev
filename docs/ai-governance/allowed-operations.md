# Allowed Operations Registry

This whitelist defines the permitted classes of terminal operations allowed for the Antigravity agent in the TSMS project.

## 1. File & Directory Management
- `ls`, `cat`, `grep`, `find`, `mkdir` (within workspace).
- `git status`, `git diff`, `git log` (read-only).
- `touch`, `cp`, `mv` (within workspace, excluding critical config).

## 2. Dependency Management
- `npm list`, `composer show`.
- `composer install` / `npm install` (only when plan-approved).

## 3. Application Execution
- `php artisan` (specifically: `test`, `make:request`, `make:service`, `queue:work`, `tinker`).
- `npm run dev`.

## 4. Custom Tooling
- `php tools/extract_payload_details.php`
- `php tools/validate_tsms_payload.php`
- `php tools/generate_tsms_payload.php`

## 5. Prohibited Operations
- `rm -rf /` (Safety).
- `curl` / `wget` to unknown domains (Security).
- Forceful mutations without `git status` check.
