<?php
/**
 * Workerman Queue Worker
 *
 * Long-running event-loop process that schedules WordPress cron and Action Scheduler
 * jobs at their exact scheduled time. Listens on a Unix socket for instant notifications
 * from WordPress and periodically rescans the database as a safety net.
 *
 * Jobs execute in isolated subprocesses (execute-job.php) that bootstrap WordPress
 * with the correct site domain, ensuring all per-site plugins and DB tables are available.
 *
 * Usage:
 *   php worker.php start [-d]   (start, optionally daemonized)
 *   php worker.php stop          (graceful stop)
 *   php worker.php restart       (graceful restart)
 *   php worker.php status        (show running status)
 */

define('QUEUE_WORKER_RUNNING', true);

// --- Load Workerman from bin/vendor ---
require __DIR__ . '/vendor/autoload.php';

// --- Load QueueWorker classes ---
// 1. Load plugin's own autoloader (classmap with all QueueWorker classes)
$plugin_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($plugin_autoload)) {
    require_once $plugin_autoload;
}

// 2. Walk up from plugin root to find site autoloader (Bedrock: has Dotenv, etc.)
$site_autoload = null;
$search = dirname(__DIR__);
for ($i = 0; $i < 10; $i++) {
    $search = dirname($search);
    if (file_exists($search . '/vendor/autoload.php')) {
        $site_autoload = $search . '/vendor/autoload.php';
        break;
    }
}
if ($site_autoload) {
    require_once $site_autoload;
}

// Verify classes are available
if (!class_exists('QueueWorker\\Config')) {
    fwrite(STDERR, "ERROR: Could not find QueueWorker classes. Run `composer install` in the plugin root.\n");
    exit(1);
}

use Workerman\Worker;
use QueueWorker\Bootstrap;
use QueueWorker\Config;
use QueueWorker\Worker_Process;

// --- Discover WordPress and load environment ---
$site_root = $site_autoload ? dirname($site_autoload, 2) : dirname(__DIR__);
Bootstrap::load_dotenv($site_root);
$wp_load = Bootstrap::discover_wp_load(__DIR__);

// --- Configuration ---
$socket_path    = Config::socket_path();
$primary_domain = getenv('DOMAIN_CURRENT_SITE') ?: 'localhost';
$execute_script = __DIR__ . '/execute-job.php';

// --- Clean up stale socket file ---
if (file_exists($socket_path)) {
    $test = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
    if (!$test) {
        unlink($socket_path);
    } else {
        fclose($test);
    }
}

// --- Create Worker and assign callbacks ---
$worker = new Worker('unix://' . $socket_path);
$worker->count = Config::worker_count();
$worker->name  = 'wp-queue-worker';

$process = new Worker_Process($wp_load, $primary_domain, $execute_script);

$worker->onWorkerStart = [$process, 'on_worker_start'];
$worker->onMessage     = [$process, 'on_message'];

Worker::runAll();
