<?php
/**
 * Workerman Queue Worker
 *
 * Long-running event-loop process that executes WordPress cron and Action Scheduler
 * jobs at their exact scheduled time. Listens on a Unix socket for instant notifications
 * from WordPress and periodically rescans the database as a safety net.
 *
 * Multiple worker processes (count=N) each bootstrap WordPress independently and
 * race to execute jobs. MySQL INSERT IGNORE prevents duplicate execution across processes.
 *
 * Usage:
 *   php worker.php start [-d]   (start, optionally daemonized)
 *   php worker.php stop          (graceful stop)
 *   php worker.php restart       (graceful restart)
 *   php worker.php status        (show running status)
 */

define('QUEUE_WORKER_RUNNING', true);

// --- Auto-discover vendor/autoload.php ---
// Check plugin-local vendor/ first, then walk up directories
$autoload = null;
$search = __DIR__;
for ($i = 0; $i < 10; $i++) {
    $search = dirname($search);
    if (file_exists($search . '/vendor/autoload.php')) {
        $autoload = $search . '/vendor/autoload.php';
        break;
    }
}
if (!$autoload) {
    fwrite(STDERR, "ERROR: Could not find vendor/autoload.php. Run `composer install`.\n");
    exit(1);
}
require_once $autoload;

// Load our own classes (the plugin entry point skips them when QUEUE_WORKER_RUNNING is true)
require_once dirname(__DIR__) . '/src/class-job-payload.php';
require_once dirname(__DIR__) . '/src/class-socket-client.php';

// --- Auto-discover wp-load.php ---
// Priority: WP_ROOT_PATH env var > walk up from plugin dir
$wp_load = null;

if (getenv('WP_ROOT_PATH')) {
    $root = rtrim(getenv('WP_ROOT_PATH'), '/');
    if (file_exists($root . '/wp-load.php')) {
        $wp_load = $root . '/wp-load.php';
    }
}

if (!$wp_load) {
    $search = dirname(__DIR__);
    for ($i = 0; $i < 10; $i++) {
        $search = dirname($search);
        // Standard WordPress
        if (file_exists($search . '/wp-load.php')) {
            $wp_load = $search . '/wp-load.php';
            break;
        }
        // Bedrock: web/wp/wp-load.php
        if (file_exists($search . '/web/wp/wp-load.php')) {
            $wp_load = $search . '/web/wp/wp-load.php';
            break;
        }
    }
}

if (!$wp_load) {
    fwrite(STDERR, "ERROR: Could not find wp-load.php. Set WP_ROOT_PATH environment variable.\n");
    exit(1);
}

// The site root is the directory containing composer.json / .env
// For Bedrock: site/ (parent of web/wp/wp-load.php -> web/ -> site/)
// For standard WP: same dir as wp-load.php
$site_root = dirname($autoload, 2);

// Load .env if Dotenv is available (Bedrock has it, standard WP doesn't)
if (class_exists('Dotenv\\Dotenv') && file_exists($site_root . '/.env')) {
    $env_files = file_exists($site_root . '/.env.local')
        ? ['.env', '.env.local']
        : ['.env'];
    $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($site_root, $env_files, false);
    $dotenv->load();
}

use Workerman\Worker;
use Workerman\Timer;

// --- Configuration ---
$socket_path     = getenv('QUEUE_WORKER_SOCKET_PATH') ?: '/tmp/wp-queue-worker.sock';
$primary_domain  = getenv('DOMAIN_CURRENT_SITE') ?: 'localhost';
$worker_count    = (int) (getenv('QUEUE_WORKER_COUNT') ?: 4);
$rescan_interval = 60;    // seconds between DB rescans
$memory_limit    = 200;   // MB — restart if exceeded
$uptime_limit    = 3600;  // seconds — restart after 1 hour

// --- Per-process State (each forked child gets its own copy) ---
$pending_timers = [];      // tracking_key => timer_id
$running_jobs   = 0;
$start_time     = time();

// Clean up stale socket file
if (file_exists($socket_path)) {
    $test = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
    if (!$test) {
        unlink($socket_path);
    } else {
        fclose($test);
    }
}

$worker = new Worker('unix://' . $socket_path);
$worker->count = $worker_count;
$worker->name  = 'wp-queue-worker';

$worker->onWorkerStart = function ($w) use (
    $wp_load,
    $primary_domain,
    $socket_path,
    &$pending_timers,
    &$running_jobs,
    &$start_time,
    $rescan_interval,
    $memory_limit,
    $uptime_limit
) {
    $start_time = time();
    $worker_id  = $w->id; // 0-based worker index

    // Make socket writable by the web group (only first worker needs to)
    if ($worker_id === 0 && file_exists($socket_path)) {
        chmod($socket_path, 0660);
    }

    // --- Bootstrap WordPress inside each worker child process ---
    $_SERVER['HTTP_HOST']      = $primary_domain;
    $_SERVER['SERVER_NAME']    = $primary_domain;
    $_SERVER['REQUEST_URI']    = '/';
    $_SERVER['SERVER_PORT']    = '443';
    $_SERVER['HTTPS']          = 'on';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    require_once $wp_load;

    // Override wp_die so plugins that call it (e.g. WooCommerce privacy
    // cleanup) throw an exception instead of terminating the process.
    // Must be after wp-load.php since add_filter isn't available before.
    add_filter('wp_die_handler', function () {
        return function ($message = '', $title = '', $args = []) {
            $msg = '';
            if ($message instanceof \WP_Error) {
                $msg = $message->get_error_message();
            } elseif (is_string($message)) {
                $msg = strip_tags($message);
            }
            throw new \RuntimeException('wp_die called: ' . ($msg ?: $title ?: 'unknown'));
        };
    });

    Worker::log(sprintf('[W%d] WordPress bootstrapped. Primary domain: %s', $worker_id, $primary_domain));

    // Load plugins active on other sites that weren't loaded during bootstrap.
    // WordPress only loads plugins active on the primary site. switch_to_blog()
    // only changes the DB prefix, it doesn't reload plugins. This ensures all
    // Action Scheduler callbacks are registered regardless of which site owns the job.
    $extra = load_network_plugins();
    if ($extra > 0) {
        Worker::log(sprintf('[W%d] Loaded %d additional plugin(s) from other network sites.', $worker_id, $extra));
    }

    // Ensure the lock table exists (idempotent)
    ensure_lock_table();

    // --- Job execution callback ---
    $execute_job = function ($payload) use (&$pending_timers, &$running_jobs, $worker_id) {
        $key = $payload->tracking_key();

        // Remove from tracking
        unset($pending_timers[$key]);

        // Atomically claim this job — INSERT IGNORE ensures only one process wins
        $lock_key = 'qw_' . substr(md5($key), 0, 40);
        if (!claim_job($lock_key)) {
            return; // Another worker already claimed this job
        }

        $running_jobs++;

        try {
            switch_to_blog($payload->site_id);

            if ($payload->source === 'action_scheduler') {
                execute_as_job($payload, $worker_id);
            } else {
                execute_cron_job($payload, $worker_id);
            }

            restore_current_blog();
        } catch (\Throwable $e) {
            Worker::log(sprintf(
                '[W%d][ERROR] Job %s on site %d failed: %s in %s:%d',
                $worker_id,
                $payload->hook,
                $payload->site_id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            if (ms_is_switched()) {
                restore_current_blog();
            }
        } finally {
            $running_jobs--;
            // Flush runtime object cache to avoid stale data across sites
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }
    };

    // Store globally so re-queue closures can reference it
    $GLOBALS['__execute_job'] = $execute_job;

    // --- Schedule a job timer ---
    $schedule_timer = function ($payload) use (&$pending_timers, $execute_job) {
        $key = $payload->tracking_key();

        // Skip if already tracked in this process
        if (isset($pending_timers[$key])) {
            return;
        }

        $delay = max(0, $payload->timestamp - time());
        $timer_id = Timer::add($delay, $execute_job, [$payload], false);
        $pending_timers[$key] = $timer_id;
    };

    $GLOBALS['__schedule_timer'] = $schedule_timer;

    // --- Initial DB scan ---
    Worker::log(sprintf('[W%d] Scanning database for pending jobs...', $worker_id));
    rescan_all_jobs($schedule_timer);
    Worker::log(sprintf('[W%d] Loaded %d pending jobs.', $worker_id, count($pending_timers)));

    // --- Periodic rescan timer ---
    // Stagger rescan across workers to avoid all hitting the DB simultaneously
    $stagger = $worker_id * 5;
    Timer::add($rescan_interval, function () use ($schedule_timer, &$pending_timers, $worker_id) {
        Worker::log(sprintf('[W%d][RESCAN] %d timers pending, rescanning...', $worker_id, count($pending_timers)));
        rescan_all_jobs($schedule_timer);
    });
    // Initial staggered delay before first periodic rescan
    if ($stagger > 0) {
        Timer::add($stagger, function () {}, null, false); // no-op to offset timing
    }

    // --- Memory and uptime watchdog ---
    Timer::add(30, function () use ($memory_limit, $uptime_limit, &$start_time, $worker_id) {
        $mem_mb = memory_get_usage(true) / 1024 / 1024;
        $uptime = time() - $start_time;

        if ($mem_mb > $memory_limit) {
            Worker::log(sprintf('[W%d][WATCHDOG] Memory %.1fMB > %dMB limit. Restarting.', $worker_id, $mem_mb, $memory_limit));
            Worker::stopAll();
        }

        if ($uptime > $uptime_limit) {
            Worker::log(sprintf('[W%d][WATCHDOG] Uptime %ds > %ds limit. Restarting.', $worker_id, $uptime, $uptime_limit));
            Worker::stopAll();
        }
    });
};

// --- Handle incoming socket messages ---
// Workerman round-robins connections across worker processes
$worker->onMessage = function ($connection, $data) use (&$pending_timers, &$running_jobs, &$start_time) {
    $data = trim($data);

    // Handle control commands
    $decoded = json_decode($data, true);
    if (is_array($decoded) && isset($decoded['command'])) {
        handle_command($connection, $decoded, $pending_timers, $running_jobs, $start_time);
        return;
    }

    // Handle job payload
    try {
        $payload = \QueueWorker\Job_Payload::from_json($data);
        $schedule_timer = $GLOBALS['__schedule_timer'];
        $schedule_timer($payload);
    } catch (\Throwable $e) {
        Worker::log('[SOCKET] Invalid payload: ' . $e->getMessage());
    }

    $connection->close();
};

// --- Helper Functions ---

/**
 * Claim a job using a DB row as an atomic flag.
 * Uses INSERT IGNORE into a lightweight lock table — only one process can claim a given key.
 * The row persists for 5 minutes to prevent re-execution.
 */
function claim_job(string $job_key): bool
{
    global $wpdb;
    $table = $wpdb->base_prefix . 'qw_job_locks';

    // Clean up expired locks (older than 5 minutes)
    $wpdb->query("DELETE FROM `$table` WHERE claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    // Attempt to claim — INSERT IGNORE fails silently if key already exists
    $result = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO `$table` (lock_key, claimed_at) VALUES (%s, NOW())",
        $job_key
    ));

    return $result === 1; // 1 = row inserted (we claimed it), 0 = already existed
}

/**
 * Ensure the job locks table exists. Called once per worker startup.
 */
function ensure_lock_table(): void
{
    global $wpdb;
    $table = $wpdb->base_prefix . 'qw_job_locks';

    $wpdb->query("CREATE TABLE IF NOT EXISTS `$table` (
        lock_key VARCHAR(64) NOT NULL PRIMARY KEY,
        claimed_at DATETIME NOT NULL
    ) ENGINE=InnoDB"
    );
}

/**
 * Load plugins that are active on other network sites but weren't loaded
 * during the primary site's bootstrap.
 *
 * WordPress only loads plugins for the bootstrapped site. In multisite,
 * switch_to_blog() only swaps the DB prefix — it doesn't reload plugins.
 * This means Action Scheduler callbacks registered by per-site plugins
 * (not network-activated) would be missing when executing jobs for those sites.
 *
 * This function:
 * 1. Collects all active plugins across every site in the network
 * 2. Includes any plugin files not already loaded
 * 3. Fires their newly-registered callbacks for bootstrap actions that
 *    have already completed (plugins_loaded, after_setup_theme, init, wp_loaded)
 *
 * @return int Number of additional plugins loaded.
 */
function load_network_plugins(): int
{
    global $wp_filter;

    if (!is_multisite()) {
        return 0;
    }

    // Collect all unique active plugins across every site
    $all_plugins = [];
    $sites = get_sites(['number' => 0, 'fields' => 'ids']);
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        foreach (get_option('active_plugins', []) as $plugin) {
            $all_plugins[$plugin] = true;
        }
        restore_current_blog();
    }

    // Build a lookup of already-included files (normalized to realpath)
    $included = [];
    foreach (get_included_files() as $f) {
        $included[$f] = true;
    }

    // Actions that have already fired during bootstrap — we'll need to
    // manually invoke any new callbacks these freshly-loaded plugins register
    $bootstrap_actions = ['plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded'];

    $loaded_count = 0;

    foreach (array_keys($all_plugins) as $plugin) {
        $file = WP_PLUGIN_DIR . '/' . $plugin;
        if (!file_exists($file)) {
            continue;
        }

        // Skip if already included
        $real = realpath($file);
        if ($real && isset($included[$real])) {
            continue;
        }

        // Snapshot current callbacks for bootstrap actions
        $before = [];
        foreach ($bootstrap_actions as $action) {
            if (isset($wp_filter[$action])) {
                $before[$action] = [];
                foreach ($wp_filter[$action]->callbacks as $pri => $cbs) {
                    $before[$action][$pri] = array_keys($cbs);
                }
            }
        }

        // Wrap in try/catch — some plugins expect per-site DB tables that
        // don't exist on the primary site (e.g. WPForms, WooCommerce PDF IPS Pro).
        // Non-fatal: the important thing is that hook callbacks get registered.
        try {
            include_once $file;
            $loaded_count++;

            // Fire any newly registered callbacks for already-completed actions.
            // Example: a plugin that hooks after_setup_theme to call its setup()
            // function — that action already fired, so we invoke the new callback now.
            foreach ($bootstrap_actions as $action) {
                if (!did_action($action) || !isset($wp_filter[$action])) {
                    continue;
                }
                foreach ($wp_filter[$action]->callbacks as $pri => $cbs) {
                    foreach ($cbs as $id => $cb) {
                        if (isset($before[$action][$pri]) && in_array($id, $before[$action][$pri], true)) {
                            continue;
                        }
                        // New callback — invoke it
                        call_user_func($cb['function']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log but continue — the plugin may have partially initialized
            // which is often enough (e.g. hook callbacks registered before the error).
            Worker::log(sprintf(
                '[PLUGIN] %s failed to fully load: %s',
                $plugin,
                $e->getMessage()
            ));
        }
    }

    return $loaded_count;
}

function execute_cron_job(\QueueWorker\Job_Payload $payload, int $worker_id): void
{
    Worker::log(sprintf('[W%d][EXEC] WP Cron: %s on site %d', $worker_id, $payload->hook, $payload->site_id));

    // Remove the event from WP cron array, then execute the hook
    wp_unschedule_event($payload->timestamp, $payload->hook, $payload->args);
    do_action_ref_array($payload->hook, $payload->args);

    // If recurring, WordPress will have already rescheduled it via the schedule_event hook
    // which will notify us through the socket
}

function execute_as_job(\QueueWorker\Job_Payload $payload, int $worker_id): void
{
    if (!class_exists('ActionScheduler_QueueRunner')) {
        Worker::log(sprintf('[W%d][SKIP] ActionScheduler not available for: %s', $worker_id, $payload->hook));
        return;
    }

    Worker::log(sprintf(
        '[W%d][EXEC] Action Scheduler #%d: %s on site %d',
        $worker_id,
        $payload->action_id,
        $payload->hook,
        $payload->site_id
    ));

    try {
        $runner = \ActionScheduler_QueueRunner::instance();
        $runner->process_action($payload->action_id);
    } catch (\Exception $e) {
        Worker::log(sprintf(
            '[W%d][ERROR] AS action #%d failed: %s',
            $worker_id,
            $payload->action_id,
            $e->getMessage()
        ));
    }
}

function rescan_all_jobs(callable $schedule_timer): void
{
    $sites = get_sites(['number' => 0, 'fields' => 'ids']);

    foreach ($sites as $site_id) {
        switch_to_blog($site_id);

        // Scan WP Cron
        $crons = _get_cron_array();
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                if (!is_array($hooks)) {
                    continue;
                }
                foreach ($hooks as $hook => $events) {
                    // Skip hooks the interceptor bypasses
                    if (in_array($hook, [
                        'wp_version_check',
                        'wp_update_plugins',
                        'wp_update_themes',
                        'action_scheduler_run_queue',
                        'action_scheduler_run_cleanup',
                    ], true)) {
                        continue;
                    }

                    foreach ($events as $event) {
                        $event_obj = (object) array_merge($event, [
                            'hook'      => $hook,
                            'timestamp' => $timestamp,
                        ]);
                        $payload = \QueueWorker\Job_Payload::from_cron_event($event_obj);
                        $schedule_timer($payload);
                    }
                }
            }
        }

        // Scan Action Scheduler
        if (function_exists('as_get_scheduled_actions')) {
            $actions = as_get_scheduled_actions([
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 500,
            ]);
            foreach ($actions as $action_id => $action) {
                $payload = \QueueWorker\Job_Payload::from_as_action($action_id);
                if ($payload) {
                    $schedule_timer($payload);
                }
            }
        }

        restore_current_blog();
    }
}

function handle_command($connection, array $cmd, array &$pending_timers, int &$running_jobs, int &$start_time): void
{
    switch ($cmd['command']) {
        case 'status':
            $mem_mb = memory_get_usage(true) / 1024 / 1024;
            $uptime = time() - $start_time;
            $hours  = floor($uptime / 3600);
            $mins   = floor(($uptime % 3600) / 60);
            $secs   = $uptime % 60;

            $response = json_encode([
                'pid'            => getmypid(),
                'uptime'         => sprintf('%dh %dm %ds', $hours, $mins, $secs),
                'pending_timers' => count($pending_timers),
                'running_jobs'   => $running_jobs,
                'memory'         => sprintf('%.1f MB', $mem_mb),
            ]);
            $connection->send($response);
            break;

        case 'restart':
            Worker::log('[COMMAND] Restart requested.');
            $connection->close();
            Worker::stopAll();
            break;

        default:
            $connection->close();
            break;
    }
}

Worker::runAll();
