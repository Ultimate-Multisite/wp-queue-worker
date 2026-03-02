<?php

namespace QueueWorker;

use Workerman\Worker;
use Workerman\Timer;

/**
 * Core worker logic for the Workerman event loop.
 *
 * Encapsulates all state and callbacks that were previously closures/globals
 * in bin/worker.php. Each Workerman child process gets its own instance.
 */
class Worker_Process
{
    /** @var string Absolute path to wp-load.php */
    private string $wp_load;

    /** @var string Primary site domain for WordPress bootstrap */
    private string $primary_domain;

    /** @var string Absolute path to execute-job.php */
    private string $execute_script;

    // --- Configuration ---
    private int $max_concurrent;
    private int $max_batch_size;
    private int $batch_timeout;
    private int $rescan_interval;
    private int $memory_limit;
    private int $uptime_limit;

    // --- Per-process state ---
    /** @var array<string, int> tracking_key => timer_id */
    private array $pending_timers = [];

    /** @var list<array{process: resource, pipes: array, payloads: list<Job_Payload>, started: int, stdout: string, stderr: string}> */
    private array $running_processes = [];

    /** @var array<int, list<Job_Payload>> site_id => [payload, ...] */
    private array $pending_batch = [];

    private int $running_jobs = 0;
    private int $start_time;

    public function __construct(string $wp_load, string $primary_domain, string $execute_script)
    {
        $this->wp_load        = $wp_load;
        $this->primary_domain = $primary_domain;
        $this->execute_script = $execute_script;

        $this->max_concurrent  = Config::max_concurrent();
        $this->max_batch_size  = Config::max_batch_size();
        $this->batch_timeout   = Config::batch_timeout();
        $this->rescan_interval = Config::rescan_interval();
        $this->memory_limit    = Config::memory_limit();
        $this->uptime_limit    = Config::uptime_limit();
        $this->start_time      = time();
    }

    /**
     * Workerman onWorkerStart callback.
     */
    public function on_worker_start(Worker $w): void
    {
        $this->start_time = time();
        $worker_id = $w->id;
        $socket_path = Config::socket_path();

        if ($worker_id === 0 && file_exists($socket_path)) {
            chmod($socket_path, 0660);
        }

        $this->bootstrap_wordpress();
        Worker::log(sprintf('[W%d] WordPress bootstrapped for scanning. Primary domain: %s', $worker_id, $this->primary_domain));

        self::ensure_lock_table();

        if ($worker_id === 0) {
            Job_Log::ensure_table();
        }

        // Batch flush timer — every 1 second
        Timer::add(1, fn() => $this->flush_batches());

        // Subprocess polling timer — every 0.5 seconds
        Timer::add(0.5, fn() => $this->poll_processes($worker_id));

        // Initial DB scan
        Worker::log(sprintf('[W%d] Scanning database for pending jobs...', $worker_id));
        $this->rescan_all_jobs();
        Worker::log(sprintf('[W%d] Loaded %d pending jobs.', $worker_id, count($this->pending_timers)));

        // Periodic rescan timer (staggered by worker ID)
        Timer::add($this->rescan_interval, function () use ($worker_id) {
            Worker::log(sprintf('[W%d][RESCAN] %d timers pending, rescanning...', $worker_id, count($this->pending_timers)));
            $this->rescan_all_jobs();
        });
        $stagger = $worker_id * 5;
        if ($stagger > 0) {
            Timer::add($stagger, function () {}, null, false);
        }

        // Memory and uptime watchdog
        Timer::add(30, function () use ($worker_id) {
            $this->check_limits($worker_id);
        });
    }

    /**
     * Workerman onMessage callback.
     */
    public function on_message($connection, string $data): void
    {
        $data = trim($data);

        $decoded = json_decode($data, true);
        if (is_array($decoded) && isset($decoded['command'])) {
            $this->handle_command($connection, $decoded);
            return;
        }

        try {
            $payload = Job_Payload::from_json($data);
            $this->schedule_timer($payload);
        } catch (\Throwable $e) {
            Worker::log('[SOCKET] Invalid payload: ' . $e->getMessage());
        }

        $connection->close();
    }

    // ------------------------------------------------------------------
    // Private methods
    // ------------------------------------------------------------------

    private function bootstrap_wordpress(): void
    {
        $_SERVER['HTTP_HOST']      = $this->primary_domain;
        $_SERVER['SERVER_NAME']    = $this->primary_domain;
        $_SERVER['REQUEST_URI']    = '/';
        $_SERVER['SERVER_PORT']    = '443';
        $_SERVER['HTTPS']          = 'on';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        require_once $this->wp_load;
    }

    /**
     * Schedule a one-shot timer for a job payload.
     */
    private function schedule_timer(Job_Payload $payload): void
    {
        $key = $payload->tracking_key();
        if (isset($this->pending_timers[$key])) {
            return;
        }
        $delay = max(0, $payload->timestamp - time());
        $timer_id = Timer::add($delay, fn($p) => $this->execute_job($p), [$payload], false);
        $this->pending_timers[$key] = $timer_id;
    }

    /**
     * Fired by timer when a job is due. Claims the job and adds to pending batch.
     */
    private function execute_job(Job_Payload $payload): void
    {
        $key = $payload->tracking_key();
        unset($this->pending_timers[$key]);

        // Check concurrency limit before claiming
        if (count($this->running_processes) >= $this->max_concurrent) {
            // Re-schedule with 2s delay
            $timer_id = Timer::add(2, fn($p) => $this->execute_job($p), [$payload], false);
            $this->pending_timers[$key] = $timer_id;
            return;
        }

        // Atomically claim — only one worker process wins
        $lock_key = 'qw_' . substr(md5($key), 0, 40);
        if (!$this->claim_job($lock_key)) {
            return;
        }

        // Collect into pending batch — flushed by the batch timer
        $this->pending_batch[$payload->site_id][] = $payload;
    }

    /**
     * Spawn a subprocess to execute a batch of jobs (all same site).
     */
    private function spawn_batch(array $payloads, int $worker_id): void
    {
        $json_array = array_map(
            fn($p) => json_decode($p->to_json(), true),
            $payloads
        );
        $json_data = json_encode($json_array);
        $cmd = sprintf('php %s --stdin', escapeshellarg($this->execute_script));

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

        $this->running_jobs++;
        $this->running_processes[] = [
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
            $this->running_jobs,
            implode(', ', array_unique($hooks))
        ));
    }

    /**
     * Flush pending batches — takes up to max_batch_size per site.
     */
    private function flush_batches(): void
    {
        foreach ($this->pending_batch as $site_id => $payloads) {
            if (empty($payloads)) {
                unset($this->pending_batch[$site_id]);
                continue;
            }
            if (count($this->running_processes) >= $this->max_concurrent) {
                break;
            }
            $batch = array_splice($this->pending_batch[$site_id], 0, $this->max_batch_size);
            // Worker ID isn't critical for batch flush logging; use 0
            $this->spawn_batch($batch, 0);
            if (empty($this->pending_batch[$site_id])) {
                unset($this->pending_batch[$site_id]);
            }
        }
    }

    /**
     * Poll running subprocesses for completion or timeout.
     */
    private function poll_processes(int $worker_id): void
    {
        foreach ($this->running_processes as $i => $proc) {
            // Read available output
            $out = stream_get_contents($proc['pipes'][1]);
            if ($out !== false && $out !== '') {
                $this->running_processes[$i]['stdout'] .= $out;
            }
            $err = stream_get_contents($proc['pipes'][2]);
            if ($err !== false && $err !== '') {
                $this->running_processes[$i]['stderr'] .= $err;
            }

            $status = proc_get_status($proc['process']);

            if (!$status['running']) {
                // Read remaining output
                $remaining = stream_get_contents($proc['pipes'][1]);
                if ($remaining) {
                    $this->running_processes[$i]['stdout'] .= $remaining;
                }
                $remaining_err = stream_get_contents($proc['pipes'][2]);
                if ($remaining_err) {
                    $this->running_processes[$i]['stderr'] .= $remaining_err;
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
                    $error_msg = trim($this->running_processes[$i]['stderr'] ?: $this->running_processes[$i]['stdout']);
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

                unset($this->running_processes[$i]);
                $this->running_jobs--;
                continue;
            }

            // Timeout check
            if (time() - $proc['started'] > $this->batch_timeout) {
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
                    $this->batch_timeout,
                    $pid
                ));

                unset($this->running_processes[$i]);
                $this->running_jobs--;
            }
        }
        // Re-index to prevent gaps
        $this->running_processes = array_values($this->running_processes);
    }

    /**
     * Scan all multisite blogs for pending WP Cron events and AS actions.
     */
    private function rescan_all_jobs(): void
    {
        $sites = get_sites(['number' => 0, 'fields' => 'ids']);

        foreach ($sites as $site_id) {
            switch_to_blog($site_id);

            // Flush stale object cache so we read fresh data from DB.
            wp_cache_delete('cron', 'options');
            wp_cache_delete('alloptions', 'options');

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
                            $payload = Job_Payload::from_cron_event($event_obj);
                            $this->schedule_timer($payload);
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
                    $payload = Job_Payload::from_as_action($action_id);
                    if ($payload) {
                        $this->schedule_timer($payload);
                    }
                }
            }

            restore_current_blog();
        }
    }

    /**
     * Atomically claim a job via INSERT IGNORE into the lock table.
     */
    private function claim_job(string $job_key): bool
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

    /**
     * Handle incoming socket commands (status, restart).
     */
    private function handle_command($connection, array $cmd): void
    {
        switch ($cmd['command']) {
            case 'status':
                $mem_mb = memory_get_usage(true) / 1024 / 1024;
                $uptime = time() - $this->start_time;
                $hours  = floor($uptime / 3600);
                $mins   = floor(($uptime % 3600) / 60);
                $secs   = $uptime % 60;

                $running_details = [];
                foreach ($this->running_processes as $proc) {
                    $hooks = [];
                    $site_id = 0;
                    $count = 0;
                    if (!empty($proc['payloads'])) {
                        $site_id = $proc['payloads'][0]->site_id;
                        $count = count($proc['payloads']);
                        foreach ($proc['payloads'] as $p) {
                            $hooks[$p->hook] = true;
                        }
                    }
                    $running_details[] = [
                        'hook'    => implode(', ', array_keys($hooks)),
                        'site_id' => $site_id,
                        'count'   => $count,
                        'elapsed' => time() - $proc['started'],
                    ];
                }

                $response = json_encode([
                    'pid'             => getmypid(),
                    'uptime'          => sprintf('%dh %dm %ds', $hours, $mins, $secs),
                    'uptime_seconds'  => $uptime,
                    'pending_timers'  => count($this->pending_timers),
                    'running_jobs'    => $this->running_jobs,
                    'memory'          => sprintf('%.1f MB', $mem_mb),
                    'running_details' => $running_details,
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

    /**
     * Check memory and uptime limits, trigger restart if exceeded.
     */
    private function check_limits(int $worker_id): void
    {
        $mem_mb = memory_get_usage(true) / 1024 / 1024;
        $uptime = time() - $this->start_time;

        if ($mem_mb > $this->memory_limit) {
            Worker::log(sprintf('[W%d][WATCHDOG] Memory %.1fMB > %dMB limit. Restarting.', $worker_id, $mem_mb, $this->memory_limit));
            Worker::stopAll();
        }

        if ($uptime > $this->uptime_limit) {
            Worker::log(sprintf('[W%d][WATCHDOG] Uptime %ds > %ds limit. Restarting.', $worker_id, $uptime, $this->uptime_limit));
            Worker::stopAll();
        }
    }

    /**
     * Create the job locks table if it doesn't exist.
     */
    public static function ensure_lock_table(): void
    {
        global $wpdb;
        $table = $wpdb->base_prefix . 'qw_job_locks';

        $wpdb->query("CREATE TABLE IF NOT EXISTS `$table` (
            lock_key VARCHAR(64) NOT NULL PRIMARY KEY,
            claimed_at DATETIME NOT NULL
        ) ENGINE=InnoDB"
        );
    }
}
