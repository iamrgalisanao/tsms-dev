# Tech Stack Profile: TSMS

## Backend
- **Framework**: Laravel 8.2+ (PHP 8.2+)
- **Database**: MySQL (Global Query Scopes for Multi-tenancy)
- **Queue**: Redis (Sharded 8 queues: `s0-s7`)
- **Authentication**: Laravel Sanctum (Ability-based `pos_api`)
- **Validation**: Strict SHA-256 Dual-checksum Integrity

## Tools & Utilities
- **Excel Export**: PhpSpreadsheet (used in `tools/extract_payload_details.php`)
- **Testing**: PHPUnit, mock-based transaction simulation
- **Scripts**: Deterministic PHP and Python tools in `tools/`

## Frontend & Styling
- **Build Tool**: Vite / Webpack Mix (Hybrid Migration)
- **CSS**: TailwindCSS (Configured in `tailwind.config.js`)
- **Core Strategy**: Responsive PITX Terminal Dashboards

## Infrastructure
- **Hosting**: Proposed high-availability Linux/MacOS environments
- **Integrations**: POS V2.1/V2.2 Final Protocol
- **Privacy Enforcement**: RA 10173 compliant isolation
