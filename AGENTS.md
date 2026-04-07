# AGENTS.md — The Perfect WP Cron

## Project Overview

WordPress plugin that replaces WP-Cron with an event-loop job queue powered by Workerman. Executes WP Cron and Action Scheduler jobs at exact scheduled times with zero polling. Provides a WP-CLI interface and admin dashboard for monitoring.

## Build Commands

```bash
composer install                    # Install PHP dependencies
npm install                         # Install Node.js dev dependencies
npm run build                       # Minify CSS and JS assets
npm run css:build                   # Minify admin.css → admin.min.css
npm run js:build                    # Minify admin.js → admin.min.js
npm run archive                     # Build distributable zip (composer install + build + zip)
```

## Project Structure

```
the-perfect-wp-cron/
├── the-perfect-wp-cron.php         # Plugin entry point
├── src/
│   ├── class-config.php            # Configuration
│   ├── class-cron-interceptor.php  # Hooks into WP Cron scheduling
│   ├── class-action-scheduler-bridge.php  # Action Scheduler integration
│   ├── class-admin-page.php        # Dashboard UI + AJAX handlers
│   ├── class-cli-commands.php      # WP-CLI `wp queue` commands
│   ├── class-job-log.php           # Job execution logging + cleanup
│   └── ...                         # Worker and queue management classes
├── assets/
│   ├── admin.css                   # Admin dashboard styles (source)
│   ├── admin.js                    # Admin dashboard JS (source)
│   ├── admin.min.css               # Minified (release build only)
│   └── admin.min.js                # Minified (release build only)
├── bin/                            # Worker entry points
├── .distignore                     # Files excluded from distribution zip
├── composer.json
└── package.json
```

## Code Style & Conventions

- **PHP version**: >= 8.1
- **Autoloading**: Classmap (`src/` directory)
- **Namespace prefix**: `QueueWorker\`
- **Constants prefix**: `QW_`
- **No PHPCS config** — follow WordPress Coding Standards conventions
- **Text domain**: Inherits from WordPress core context
- **Network plugin**: `Network: true`

## Key Patterns

- Registers on `init` hook via static `Cron_Interceptor::register()`
- Action Scheduler bridge registers on `action_scheduler_init`
- WP-CLI commands under `wp queue` namespace
- Worker process detection via `QUEUE_WORKER_RUNNING` constant
- Job log table created on activation, cleaned daily via cron
- Admin page registered on `network_admin_menu` (multisite) or `admin_menu`

## Important Notes

- `*.min.js` and `*.min.css` files are generated during release builds — do not commit on feature branches
- The `bin/` directory contains Workerman worker entry points
- `.distignore` lists files excluded from the release zip

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
