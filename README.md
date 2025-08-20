# Project Name

A brief description of what this project does and who it's for.

## Table of Contents
- [Installation](#installation)
- [Usage](#usage)
- [Development](#development)
- [Contributing](#contributing)
- [License](#license)

## Installation
Describe how to install and set up the project. Include prerequisites, dependencies, and configuration steps.

## Usage
Provide instructions and examples for using the application.

## Development
- Directory structure overview
- How to run tests
- How to build or deploy

### Horizon (Queue Monitoring & Workers)
Horizon supervises Redis queues and provides a dashboard for transaction processing throughput & failures.

Access:
- Path: `/command-center` (HORIZON_PATH)
- Protected by `viewHorizon` gate (roles: admin or ops)

Queues Used:
- `transaction-processing` – critical transaction validation / processing
- `forwarding` – webapp forwarding & external callbacks
- `low` – housekeeping (pruning, watchdog, bulk generation, retries)

Environment Variables (see `.env`):
```
HZ_HIGH_PROCESSES, HZ_FORWARD_PROCESSES, HZ_LOW_PROCESSES
HORIZON_TRIM_RECENT, HORIZON_TRIM_COMPLETED, HORIZON_TRIM_RECENT_FAILED, HORIZON_TRIM_FAILED
HORIZON_PREFIX
```

Deployment (systemd example):
```
[Unit]
Description=TSMS Horizon
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/tsms/artisan horizon
ExecStop=/usr/bin/php /var/www/tsms/artisan horizon:terminate
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Rolling Deploy:
1. Pull code & install dependencies
2. Run migrations
3. `php artisan horizon:terminate` (workers restart automatically)

Monitoring Guidance:
- Watch `Wait` > 2s on `transaction-processing` → scale processes
- Investigate spikes in `failed` tagged jobs (e.g. `transaction:pk=...`)
- Adjust process counts via env vars; no redeploy needed

Job Tagging:
- Critical jobs expose tags (transaction:pk, domain:forwarding, domain:bulk-generation, domain:retry) for filtering.

Security:
- Only admin / ops roles pass `viewHorizon` gate.
- Consider IP allowlist or VPN for production access.

Retention:
- Completed jobs trimmed after 120 minutes (adjust via env)
- Failed jobs retained 30 days for audit / post-mortem.

Back Pressure Strategy:
- If queue depth grows & wait increases, temporarily throttle submission endpoints or increase `HZ_HIGH_PROCESSES`.


## Contributing
Guidelines for contributing to the project.

## License
Specify the license for the project.
