# WP Queue Worker

Event-loop job queue for WordPress. Runs WP Cron and Action Scheduler jobs at their exact scheduled time using [Workerman](https://github.com/walkor/workerman) — no HTTP polling, no missed schedules.

## How It Works

A long-running PHP process listens on a Unix socket. When WordPress schedules a cron event or Action Scheduler action, the plugin immediately notifies the worker via the socket. The worker sets a precise timer and fires the job at the right moment.

Multiple worker processes share the socket. A lightweight database lock table ensures each job runs exactly once even with concurrent workers.

A periodic database rescan acts as a safety net to catch any jobs that were scheduled before the worker started or that slipped through without a socket notification.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Linux (Workerman uses `pcntl_fork`)
- Composer

## Installation

### Via Composer (recommended)

Add the repository and require the package:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Ultimate-Multisite/wp-queue-worker.git"
    }
  ],
  "require": {
    "ultimate-multisite/wp-queue-worker": "dev-main"
  }
}
```

Then run:

```bash
composer update ultimate-multisite/wp-queue-worker
```

The plugin installs to `wp-content/plugins/wp-queue-worker/` (or `web/app/plugins/` on Bedrock).

### Manual Installation

Clone or download this repo into your plugins directory, then run `composer install` inside the plugin folder to get Workerman.

## Configuration

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DOMAIN_CURRENT_SITE` | `localhost` | Primary domain for WordPress bootstrap |
| `QUEUE_WORKER_SOCKET_PATH` | `/tmp/wp-queue-worker.sock` | Unix socket file path |
| `QUEUE_WORKER_COUNT` | `4` | Number of worker processes |
| `WP_ROOT_PATH` | *(auto-detected)* | Path to directory containing `wp-load.php` |

### PHP Constants

Define `QUEUE_WORKER_SOCKET_PATH` in `wp-config.php` to override the socket path from the WordPress side:

```php
define('QUEUE_WORKER_SOCKET_PATH', '/run/wp-queue-worker.sock');
```

## Usage

### Starting the Worker

```bash
# Start in foreground (useful for debugging)
php wp-content/plugins/wp-queue-worker/bin/worker.php start

# Start as a background daemon
php wp-content/plugins/wp-queue-worker/bin/worker.php start -d

# Stop the worker
php wp-content/plugins/wp-queue-worker/bin/worker.php stop

# Restart
php wp-content/plugins/wp-queue-worker/bin/worker.php restart

# Check status
php wp-content/plugins/wp-queue-worker/bin/worker.php status
```

### Bedrock Path

```bash
php web/app/plugins/wp-queue-worker/bin/worker.php start
```

### systemd Service (Production)

Create `/etc/systemd/system/wp-queue-worker.service`:

```ini
[Unit]
Description=WordPress Queue Worker
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/example.com/current
ExecStart=/usr/bin/php web/app/plugins/wp-queue-worker/bin/worker.php start
Restart=always
RestartSec=5
StandardOutput=append:/var/log/wp-queue-worker.log
StandardError=append:/var/log/wp-queue-worker-error.log
MemoryMax=1G
Environment=WP_ENV=production
Environment=QUEUE_WORKER_COUNT=4

[Install]
WantedBy=multi-user.target
```

Then enable and start:

```bash
sudo systemctl enable wp-queue-worker
sudo systemctl start wp-queue-worker
```

### WP-CLI Commands

The plugin registers a `wp queue` command group:

```bash
# Check worker status
wp queue status

# Rescan database and send all pending jobs to the worker
wp queue populate

# Restart the worker (relies on systemd to auto-restart)
wp queue restart
```

## Architecture

```
WordPress (web request)          Worker Process (long-running)
+-----------------------+        +---------------------------+
| Cron_Interceptor      |        | Workerman event loop      |
|   schedule_event hook |------->|   Unix socket listener    |
|                       | socket |   Timer-based execution   |
| AS_Bridge             |        |   DB rescan (safety net)  |
|   stored_action hook  |------->|   Memory/uptime watchdog  |
+-----------------------+        +---------------------------+
```

**Plugin side** (runs in every web request):
- `Cron_Interceptor`: Hooks `schedule_event` and sends new cron events to the worker
- `Action_Scheduler_Bridge`: Hooks `action_scheduler_stored_action` and disables the default AS queue runner

**Worker side** (runs as a system service):
- Bootstraps WordPress in each child process
- Listens for job notifications on a Unix socket
- Sets Workerman timers to fire at exact scheduled times
- Uses `INSERT IGNORE` for atomic job claiming across processes
- Rescans the database periodically as a fallback
- Auto-restarts on high memory usage or after a configurable uptime limit

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
