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

// --- Auto-discover vendor/autoload.php ---
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
        if (file_exists($search . '/wp-load.php')) {
            $wp_load = $search . '/wp-load.php';
            break;
        }
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

$site_root = dirname($autoload, 2);

// Load .env if Dotenv is available (Bedrock)
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
$worker_count    = (int) (getenv('QUEUE_WORKER_COUNT') ?: 2);
$max_concurrent  = (int) (getenv('QUEUE_WORKER_MAX_CONCURRENT') ?: 1); // per worker process
$job_timeout     = 300;   // seconds — subprocess hard limit
$max_batch_size  = (int) (getenv('QUEUE_WORKER_MAX_BATCH_SIZE') ?: 3); // max jobs per subprocess batch
$rescan_interval = 60;    // seconds between DB rescans
$memory_limit    = 200;   // MB — restart if exceeded
$uptime_limit    = 3600;  // seconds — restart after 1 hour

// Path to the subprocess script
$execute_script = __DIR__ . '/execute-job.php';

// --- Per-process State (each forked child gets its own copy) ---
$pending_timers    = [];   // tracking_key => timer_id
$running_processes = [];   // index => ['process', 'pipes', 'payloads', 'started', 'stdout', 'stderr']
$pending_batch     = [];   // site_id => [payload, payload, ...]
$running_jobs      = 0;
$start_time        = time();

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
    $execute_script,
    &$pending_timers,
    &$running_processes,
    &$pending_batch,
    &$running_jobs,
    &$start_time,
    $max_concurrent,
    $max_batch_size,
    $job_timeout,
    $rescan_interval,
    $memory_limit,
    $uptime_limit
) {
    $start_time = time();
    $worker_id  = $w->id;

    if ($worker_id === 0 && file_exists($socket_path)) {
        chmod($socket_path, 0660);
    }

    // --- Bootstrap WordPress for DB scanning only ---
    // Jobs execute in subprocesses with correct per-site context.
    $_SERVER['HTTP_HOST']      = $primary_domain;
    $_SERVER['SERVER_NAME']    = $primary_domain;
    $_SERVER['REQUEST_URI']    = '/';
    $_SERVER['SERVER_PORT']    = '443';
    $_SERVER['HTTPS']          = 'on';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    require_once $wp_load;

    Worker::log(sprintf('[W%d] WordPress bootstrapped for scanning. Primary domain: %s', $worker_id, $primary_domain));

    ensure_lock_table();

    // --- Spawn a subprocess to execute a batch of jobs (all same site) ---
    $spawn_batch = function (array $payloads) use (
        &$running_processes,
        &$running_jobs,
        $worker_id,
        $execute_script
    ) {
        $json_array = array_map(
            fn($p) => json_decode($p->to_json(), true),
            $payloads
        );
        $json_data = json_encode($json_array);
        $cmd = sprintf('php %s --stdin', escapeshellarg($execute_script));

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            Worker::log(sprintf(
                '[W%d][ERROR] Failed to spawn subprocess for batch of %d jobs on site %d',
                $worker_id,
                count($payloads),
                $payloads[0]->site_id
            ));
            return;
        }

        // Write JSON payload to stdin, then close
        fwrite($pipes[0], $json_data);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $running_jobs++;
        $running_processes[] = [
            'process'  => $process,
            'pipes'    => $pipes,
            'payloads' => $payloads,
            'started'  => time(),
            'stdout'   => '',
            'stderr'   => '',
        ];

        $hooks = array_map(fn($p) => $p->hook, $payloads);
        Worker::log(sprintf(
            '[W%d][SPAWN] Batch: %d jobs on site %d (pid %d, %d running): %s',
            $worker_id,
            count($payloads),
            $payloads[0]->site_id,
            proc_get_status($process)['pid'] ?? 0,
            $running_jobs,
            implode(', ', array_unique($hooks))
        ));
    };

    // --- Job execution callback (fired by timer) — collects into batch ---
    $execute_job = function ($payload) use (
        &$pending_timers,
        &$pending_batch,
        &$running_processes,
        $max_concurrent
    ) {
        $key = $payload->tracking_key();
        unset($pending_timers[$key]);

        // Check concurrency limit BEFORE claiming
        if (count($running_processes) >= $max_concurrent) {
            // Re-schedule with 2s delay
            $timer_id = Timer::add(2, $GLOBALS['__execute_job'], [$payload], false);
            $pending_timers[$key] = $timer_id;
            return;
        }

        // Atomically claim — only one worker process wins
        $lock_key = 'qw_' . substr(md5($key), 0, 40);
        if (!claim_job($lock_key)) {
            return;
        }

        // Collect into pending batch — flushed by the batch timer
        $pending_batch[$payload->site_id][] = $payload;
    };

    $GLOBALS['__execute_job'] = $execute_job;

    // --- Schedule a job timer ---
    $schedule_timer = function ($payload) use (&$pending_timers, $execute_job) {
        $key = $payload->tracking_key();
        if (isset($pending_timers[$key])) {
            return;
        }
        $delay = max(0, $payload->timestamp - time());
        $timer_id = Timer::add($delay, $execute_job, [$payload], false);
        $pending_timers[$key] = $timer_id;
    };

    $GLOBALS['__schedule_timer'] = $schedule_timer;

    // --- Flush pending batches every 1 second ---
    Timer::add(1, function () use (&$pending_batch, &$running_processes, $max_concurrent, $max_batch_size, $spawn_batch) {
        foreach ($pending_batch as $site_id => $payloads) {
            if (empty($payloads)) {
                unset($pending_batch[$site_id]);
                continue;
            }
            if (count($running_processes) >= $max_concurrent) {
                break;
            }
            // Take at most max_batch_size from the front
            $batch = array_splice($pending_batch[$site_id], 0, $max_batch_size);
            $spawn_batch($batch);
            if (empty($pending_batch[$site_id])) {
                unset($pending_batch[$site_id]);
            }
        }
    });

    // --- Poll running subprocesses for completion ---
    Timer::add(0.5, function () use (&$running_processes, &$running_jobs, $worker_id, $job_timeout) {
        foreach ($running_processes as $i => $proc) {
            // Read available output
            $out = stream_get_contents($proc['pipes'][1]);
            if ($out !== false && $out !== '') {
                $running_processes[$i]['stdout'] .= $out;
            }
            $err = stream_get_contents($proc['pipes'][2]);
            if ($err !== false && $err !== '') {
                $running_processes[$i]['stderr'] .= $err;
            }

            $status = proc_get_status($proc['process']);

            if (!$status['running']) {
                // Read remaining output
                $remaining = stream_get_contents($proc['pipes'][1]);
                if ($remaining) {
                    $running_processes[$i]['stdout'] .= $remaining;
                }
                $remaining_err = stream_get_contents($proc['pipes'][2]);
                if ($remaining_err) {
                    $running_processes[$i]['stderr'] .= $remaining_err;
                }

                fclose($proc['pipes'][1]);
                fclose($proc['pipes'][2]);
                proc_close($proc['process']);

                $exit_code = $status['exitcode'];
                $payloads  = $proc['payloads'];
                $elapsed   = time() - $proc['started'];
                $site_id   = $payloads[0]->site_id;
                $count     = count($payloads);

                if ($exit_code === 0) {
                    Worker::log(sprintf(
                        '[W%d][DONE] Batch: %d jobs on site %d (%ds)',
                        $worker_id,
                        $count,
                        $site_id,
                        $elapsed
                    ));
                } else {
                    $error_msg = trim($running_processes[$i]['stderr'] ?: $running_processes[$i]['stdout']);
                    if (strlen($error_msg) > 500) {
                        $error_msg = substr($error_msg, 0, 500) . '...';
                    }
                    Worker::log(sprintf(
                        '[W%d][FAIL] Batch: %d jobs on site %d (exit %d, %ds): %s',
                        $worker_id,
                        $count,
                        $site_id,
                        $exit_code,
                        $elapsed,
                        $error_msg
                    ));
                }

                unset($running_processes[$i]);
                $running_jobs--;
                continue;
            }

            // Timeout check
            if (time() - $proc['started'] > $job_timeout) {
                $payloads = $proc['payloads'];
                $pid = $status['pid'];
                proc_terminate($proc['process'], 9);
                fclose($proc['pipes'][1]);
                fclose($proc['pipes'][2]);
                proc_close($proc['process']);

                Worker::log(sprintf(
                    '[W%d][TIMEOUT] Batch: %d jobs on site %d exceeded %ds limit (pid %d)',
                    $worker_id,
                    count($payloads),
                    $payloads[0]->site_id,
                    $job_timeout,
                    $pid
                ));

                unset($running_processes[$i]);
                $running_jobs--;
            }
        }
        // Re-index to prevent gaps
        $running_processes = array_values($running_processes);
    });

    // --- Initial DB scan ---
    Worker::log(sprintf('[W%d] Scanning database for pending jobs...', $worker_id));
    rescan_all_jobs($schedule_timer);
    Worker::log(sprintf('[W%d] Loaded %d pending jobs.', $worker_id, count($pending_timers)));

    // --- Periodic rescan timer ---
    $stagger = $worker_id * 5;
    Timer::add($rescan_interval, function () use ($schedule_timer, &$pending_timers, $worker_id) {
        Worker::log(sprintf('[W%d][RESCAN] %d timers pending, rescanning...', $worker_id, count($pending_timers)));
        rescan_all_jobs($schedule_timer);
    });
    if ($stagger > 0) {
        Timer::add($stagger, function () {}, null, false);
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
$worker->onMessage = function ($connection, $data) use (&$pending_timers, &$running_jobs, &$start_time) {
    $data = trim($data);

    $decoded = json_decode($data, true);
    if (is_array($decoded) && isset($decoded['command'])) {
        handle_command($connection, $decoded, $pending_timers, $running_jobs, $start_time);
        return;
    }

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

function claim_job(string $job_key): bool
{
    global $wpdb;
    $table = $wpdb->base_prefix . 'qw_job_locks';

    $wpdb->query("DELETE FROM `$table` WHERE claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    $result = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO `$table` (lock_key, claimed_at) VALUES (%s, NOW())",
        $job_key
    ));

    return $result === 1;
}

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

function handle_command($connection, array $cmd, array $pending_timers, int $running_jobs, int $start_time): void
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
