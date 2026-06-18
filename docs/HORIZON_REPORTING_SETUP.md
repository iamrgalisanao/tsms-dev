# Horizon reporting workers setup

This document describes how to run and tune Horizon workers dedicated to the `reporting` queue.

Why use dedicated reporting workers
- Reporting jobs can be CPU/IO heavy. Keep them separate from latency-sensitive queues (transaction-processing) to avoid impacting user-facing job throughput.
- Horizon supervisors let you control concurrency, retries, and visibility for reporting workloads.

What we added
- A `reporting-supervisor` is present in `config/horizon.php` for `production`, `staging`, and `local` environments. It listens on the `reporting` queue and is intentionally conservative by default.

Recommended environment variables
- HZ_REPORTING_PROCESSES (default: 2 in production): number of worker processes for reporting.
- HORIZON_CONNECTION / QUEUE_REDIS_CONNECTION: ensure Horizon points to the correct Redis instance.

Systemd unit example (deploy on one or more app nodes):

```ini
[Unit]
Description=Laravel Horizon (reporting)
After=network.target redis.service

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /path/to/artisan horizon
ExecReload=/usr/bin/php /path/to/artisan horizon:terminate
Environment=APP_ENV=production
Environment=HORIZON_CONNECTION=redis
Environment=HZ_REPORTING_PROCESSES=2

[Install]
WantedBy=multi-user.target
```

Notes
- The systemd unit runs the entire Horizon dashboard which manages all supervisors configured in `config/horizon.php`. If you want only the reporting supervisor on a host, set environment overrides or run a custom supervisor process (advanced).
- Ensure your `reporting` queue name matches the `onQueue('reporting')` used in reporting job constructors.
- Point `reporting` DB connection to a read-replica to avoid putting extra read load on the primary OLTP DB.

Monitoring & alerting
- Use Horizon dashboard for immediate visibility.
- Export job metrics (runtime / failures) to your monitoring system (Datadog/Prometheus). At minimum, log job duration and rows scanned inside the job (we already log window boundaries).

Operational checklist before enabling frequent runs
1. Ensure `reporting` DB is a read-replica.
2. Tune `HZ_REPORTING_PROCESSES` conservatively and test at scale.
3. Add alerting on long job runtimes, high failure rates, and DB replication lag.
