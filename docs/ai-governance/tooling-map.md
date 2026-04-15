# Tooling Map

| Task Category | Approved Tool / Command | Context / Guardrails |
|---------------|--------------------------|-----------------------|
| **Data Extraction** | `php tools/extract_payload_details.php` | Target `tools/data` for raw JSON. |
| **Integrity Check** | `php tools/validate_tsms_payload.php` | Ensure checksums match V2.2 specifications. |
| **Testing** | `php artisan test` or `./vendor/bin/phpunit` | Must include multi-tenant isolation tests. |
| **Seeding** | `php tools/generate_tsms_payload.php` | Generate mock data only for development. |
| **Governance** | `docs/master_prompt.md` | Primary source for agent behavioral policy. |
| **Privacy Audit** | `docs/compliance/*` | Refer to PII hygiene skills before handling payloads. |
| **Queue Control** | `php artisan queue:work --queue=s0` | Monitor specific tenant-sharded queues. |
