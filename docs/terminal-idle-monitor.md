# POS Terminal Idle Monitor Guide

The **POS Terminal Idle Monitor** is a maintenance background task designed to detect terminals that have become inactive. It helps administrators identify potential connectivity issues, hardware failures, or software crashes by monitoring when a terminal last "checked in" or sent a sale.

---

## 🚀 How to Enable

The monitor is controlled via environment variables in your `.env` file. To activate it, ensure the following is set:

```env
TSMS_IDLE_MONITOR_ENABLED=true
```

Once enabled, the monitor will automatically run on every application tick (governed by the Laravel scheduler).

---

## ⚙️ Configuration Parameters

You can fine-tune the monitor's behavior using these `.env` variables:

| Variable | Default | Description |
| :--- | :--- | :--- |
| `TSMS_IDLE_MONITOR_SCAN_MIN` | `5` | How often (in minutes) the monitor should perform a scan. |
| `TSMS_IDLE_MONITOR_IDLE_DEFAULT` | `3600` | The fallback idle threshold in seconds (1 hour). |
| `TSMS_IDLE_MONITOR_ACTIVITY_BASIS` | `last_seen` | Basis for activity: `last_seen` (heartbeats), `last_sale` (transactions), or `composite` (both). |
| `TSMS_IDLE_MONITOR_MULTIPLIER` | `3` | Multiplier applied to a terminal's `heartbeat_threshold` to calculate its specific idle limit. Set to `0` to use only the default threshold. |
| `TSMS_IDLE_MONITOR_DEDUPE_TTL` | `1800` | Prevention window (seconds) to avoid duplicate logs for the same idle terminal. |
| `TSMS_IDLE_MONITOR_TENANT_SUMMARY` | `true` | When enabled, logs a high-level summary per tenant in the Audit Logs. |

---

## 📊 Monitoring and Visibility

The monitor communicates its findings through two primary channels:

### 1. System Logs (`TERMINAL_IDLE_DETECTED`)
Every time a terminal is newly identified as idle, a **Warning** log is generated.
- **Severity**: Warning
- **Log Type**: `TERMINAL_IDLE_DETECTED`
- **Context**: Includes specific idle duration, the last seen timestamp, and terminal metadata.

### 2. Audit Logs (`IDLE_MONITOR_SUMMARY`)
After every scan, a summary log is written to provide a health snapshot of the entire ecosystem.
- **Includes**: Total terminals scanned, new idles detected, and terminals that have recovered since the last scan.

---

## 🛠 Operational Details

- **Deduplication**: To prevent log flooding, the system caches the "idle" status. It will not log a terminal as idle again until it has either recovered or the `DEDUPE_TTL` has expired.
- **Recovery**: When a terminal previously marked as idle sends a new signal, a `TERMINAL_RECOVERED` Info log is emitted.
- **Shard-Aware**: The monitor is optimized to scan terminals efficiently without overloading the database.

---

> [!TIP]
> For environments with strict sales requirements, set `TSMS_IDLE_MONITOR_ACTIVITY_BASIS=last_sale`. This ensures you are alerted if a terminal is "online" but failing to process actual revenue transactions.
