# The Perfect WP Cron

Event-loop job queue for WordPress. Replaces WP-Cron's poll-on-visit model and system cron's once-per-minute limitation with a long-running [Workerman](https://github.com/walkor/workerman) process that executes every WP Cron event and Action Scheduler action at its exact scheduled time — zero polling, zero delay.

## Who Is It For

- **Multisite operators** running dozens or hundreds of sites where missed cron events and overlapping runners are a constant problem.
- **Hosting providers** who need predictable, observable background processing without per-site cron entries.
- **Sites with heavy Action Scheduler workloads** (WooCommerce Subscriptions, background imports, bulk email) that need parallel execution with per-job timeouts.
- **Anyone who needs precise scheduling** — if a job is scheduled for 14:32:07, it runs at 14:32:07, not whenever the next visitor arrives or the next minute ticks over.

## Advantages Over WP-Cron

| WP-Cron | The Perfect WP Cron |
|---|---|
| Triggers on page visits — low-traffic sites miss schedules | Triggers at exact scheduled time via event-loop timer |
| Adds latency to a visitor's request | Runs in a separate process — zero impact on web requests |
| Single-threaded — one job at a time | Configurable parallel workers and concurrency |
| No timeout protection | Per-job SIGALRM timeout stops runaway jobs |
| No visibility into what ran or failed | Admin dashboard + per-job log table with duration and errors |

## Advantages Over System Cron

| System Cron (`* * * * *`) | The Perfect WP Cron |
|---|---|
| Minimum 1-minute granularity | Sub-second precision via Workerman timers |
| Polls the database every minute even if nothing is due | Socket notification — the worker knows instantly when a new job is scheduled |
| One WP bootstrap per cron run | Batches jobs by site — multiple jobs share one WP bootstrap |
| Separate cron entry per site (multisite) | Single process handles all sites in the network |
| No built-in concurrency | Configurable worker count and max concurrent subprocesses |
| No automatic restart | Uptime + memory watchdog, designed for systemd auto-restart |

## Disadvantages

- **Requires CLI/SSH access.** You need to run a long-lived PHP process, typically via systemd. Shared hosting without shell access won't work.
- **Linux only.** Workerman requires `pcntl_fork` and `pcntl_signal`, which are not available on Windows or macOS in production.
- **Workerman dependency.** Adds ~200KB to your vendor directory. The process must be managed (started, monitored, restarted) outside of WordPress.
- **More complex than default cron.** There's a process to monitor. If it stops unexpectedly and systemd isn't configured, jobs won't run until someone notices.
- **Socket communication.** The web server's PHP process must be able to write to the Unix socket. File permissions matter.

## Requirements

- PHP 8.1+
- `pcntl` extension (standard on Linux, verify with `php -m | grep pcntl`)
- Linux (Workerman uses `pcntl_fork`)
- WordPress 6.0+
- Composer

## Installation

### Via Composer (recommended)

Add the repository and require the package:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Ultimate-Multisite/the-perfect-wp-cron.git"
    }
  ],
  "require": {
    "ultimate-multisite/the-perfect-wp-cron": "dev-main"
  }
}
```

Then run:

```bash
composer update ultimate-multisite/the-perfect-wp-cron
```

The plugin installs to `wp-content/plugins/the-perfect-wp-cron/` (or `web/app/plugins/` on Bedrock).

### Manual Installation

Clone into your plugins directory and install the Workerman dependency:

```bash
cd wp-content/plugins
git clone https://github.com/Ultimate-Multisite/the-perfect-wp-cron.git
cd the-perfect-wp-cron
composer install --no-dev
```

Activate the plugin in wp-admin (or network-activate on multisite).

## Configuration

Every setting can be configured via PHP constant (in `wp-config.php`) or environment variable. Constants take priority over env vars. All settings have sensible defaults — zero configuration is required to get started.

| Constant / Env Var | Default | Description |
|---|---|---|
| `QUEUE_WORKER_SOCKET_PATH` | `/tmp/the-perfect-wp-cron.sock` | Unix socket path |
| `QUEUE_WORKER_COUNT` | `2` | Number of worker processes (Workerman forks) |
| `QUEUE_WORKER_MAX_CONCURRENT` | `1` | Max concurrent subprocesses per worker |
| `QUEUE_WORKER_MAX_BATCH_SIZE` | `50` | Max jobs per subprocess batch |
| `QUEUE_WORKER_JOB_TIMEOUT` | `300` | Per-job timeout in seconds (SIGALRM) |
| `QUEUE_WORKER_BATCH_TIMEOUT` | `3600` | Subprocess timeout in seconds (safety net) |
| `QUEUE_WORKER_RESCAN_INTERVAL` | `60` | Seconds between database rescans |
| `QUEUE_WORKER_MEMORY_LIMIT` | `200` | Memory limit in MB before auto-restart |
| `QUEUE_WORKER_UPTIME_LIMIT` | `3600` | Max uptime in seconds before auto-restart |
| `QUEUE_WORKER_LOG_FILE` | auto-detect | Path to log for admin viewer |
| `QUEUE_WORKER_LOG_RETENTION` | `7` | Days to keep job log entries |
| `DOMAIN_CURRENT_SITE` | `localhost` | Primary domain for WP bootstrap in worker |
| `WP_ROOT_PATH` | auto-detect | Path to directory containing `wp-load.php` |

Example `wp-config.php`:

```php
define('QUEUE_WORKER_SOCKET_PATH', '/run/the-perfect-wp-cron.sock');
define('QUEUE_WORKER_COUNT', 4);
define('QUEUE_WORKER_JOB_TIMEOUT', 600);
define('QUEUE_WORKER_LOG_FILE', '/var/log/the-perfect-wp-cron.log');
```

## Usage

### Starting the Worker

```bash
# Foreground (for debugging)
php wp-content/plugins/the-perfect-wp-cron/bin/worker.php start

# Daemonized
php wp-content/plugins/the-perfect-wp-cron/bin/worker.php start -d

# Stop / Restart / Status
php wp-content/plugins/the-perfect-wp-cron/bin/worker.php stop
php wp-content/plugins/the-perfect-wp-cron/bin/worker.php restart
php wp-content/plugins/the-perfect-wp-cron/bin/worker.php status
```

Bedrock: replace `wp-content/plugins` with `web/app/plugins`.

### systemd Service (Production)

Create `/etc/systemd/system/the-perfect-wp-cron.service`:

```ini
[Unit]
Description=WordPress Queue Worker
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/example.com/current
ExecStart=/usr/bin/php web/app/plugins/the-perfect-wp-cron/bin/worker.php start
Restart=always
RestartSec=5
StandardOutput=append:/var/log/the-perfect-wp-cron.log
StandardError=append:/var/log/the-perfect-wp-cron-error.log
MemoryMax=1G
Environment=WP_ENV=production
Environment=QUEUE_WORKER_COUNT=4

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable the-perfect-wp-cron
sudo systemctl start the-perfect-wp-cron
```

### WP-CLI Commands

```bash
wp queue status      # Show worker PID, uptime, memory, pending/running jobs
wp queue populate    # Rescan — send all pending jobs to the worker
wp queue restart     # Graceful restart (systemd auto-restarts)
```

### Admin Dashboard

After activating the plugin, a **Queue Worker** page appears under:
- **Network Admin > Settings** (multisite)
- **Tools** (single site)

The dashboard shows:
1. **Worker Status** — running/stopped, PID, uptime, memory, currently executing jobs. Auto-refreshes every 10 seconds.
2. **Per-Site Resource Usage** — which sites consume the most CPU time over the last 24 hours.
3. **Job History** — searchable, filterable, sortable log of every executed job with status, duration, and error messages.
4. **Recent Log Entries** — tail of the worker log.

## Architecture

```
WordPress Request                    Worker Process (Workerman)
+---------------------------+        +------------------------------------+
| Cron_Interceptor          |        | Event loop (libevent/select)       |
|   hooks schedule_event    |------->|   Unix socket listener             |
|                           | socket |   Timer per job (exact timestamp)  |
| Action_Scheduler_Bridge   |        |   Periodic DB rescan (safety net)  |
|   hooks stored_action     |------->|   Memory + uptime watchdog         |
+---------------------------+        +------------------------------------+
                                                    |
                                        Timer triggers at scheduled time
                                                    |
                                        Claim job (INSERT IGNORE lock)
                                                    |
                                          Batch by site_id
                                                    |
                                     +------------------------------+
                                     | Subprocess: execute-job.php  |
                                     |   Bootstrap WP for site      |
                                     |   For each job in batch:     |
                                     |     SIGALRM timeout guard    |
                                     |     Run hook / AS action     |
                                     |     Log result to qw_job_log |
                                     +------------------------------+
```

**Flow:**
1. WordPress schedules a cron event or Action Scheduler action.
2. The plugin intercepts the schedule call and sends a JSON payload to the worker via Unix socket.
3. The worker sets a Workerman timer for the job's exact timestamp.
4. When the timer triggers, the worker atomically claims the job via `INSERT IGNORE` into a lock table (prevents duplicate execution across workers).
5. Claimed jobs are batched by `site_id` and flushed to a subprocess every second.
6. The subprocess (`execute-job.php`) bootstraps WordPress for the target site's domain, executes each job with a per-job SIGALRM timeout, logs results to the `qw_job_log` table, and exits.
7. The worker polls subprocesses for completion and logs batch results.
8. A periodic database rescan catches any jobs that arrived before the worker started or bypassed socket notification.

## Troubleshooting

**Worker won't start — "Address already in use"**
A leftover socket exists. The worker tries to clean it up automatically, but if another process holds it: `rm /tmp/the-perfect-wp-cron.sock` (or your configured path).

**Jobs aren't executing**
1. Check `wp queue status` — is the worker running?
2. Check `wp queue populate` — does it find pending jobs?
3. Check the worker log for errors.
4. Verify the web server user can write to the socket path.

**"Could not find wp-load.php"**
Set the `WP_ROOT_PATH` environment variable to the directory containing `wp-load.php` (for standard WP) or `web/wp/wp-load.php`'s parent (Bedrock auto-detected).

**Socket permission denied**
The worker creates the socket with mode 0660. Ensure the web server user (`www-data`) and the worker process user are in the same group, or configure the socket path to a directory both can access.

**Per-job timeout stops a legitimate long-running job**
Increase `QUEUE_WORKER_JOB_TIMEOUT` (default 300 seconds). For specific hooks that need more time, consider breaking the work into smaller chunks.

**High memory usage / frequent restarts**
The watchdog restarts workers when memory exceeds `QUEUE_WORKER_MEMORY_LIMIT` (default 200 MB) or uptime exceeds `QUEUE_WORKER_UPTIME_LIMIT` (default 3600 seconds). These are safety nets — increase them if your workload legitimately needs more resources, or investigate memory leaks in the jobs themselves.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
