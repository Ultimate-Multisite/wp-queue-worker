<?php

namespace QueueWorker;

/**
 * Executes a batch of job payloads inside a WordPress environment.
 *
 * Spawned by Worker_Process in a subprocess. Each job gets a per-job
 * SIGALRM timeout and results are logged to the qw_job_log table.
 */
class Job_Executor
{
    private int $site_id;
    private int $job_timeout;
    private bool $has_pcntl;

    public function __construct(int $site_id)
    {
        $this->site_id     = $site_id;
        $this->job_timeout = Config::job_timeout();
        $this->has_pcntl   = function_exists('pcntl_async_signals');

        if ($this->has_pcntl) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Execute all payloads and return an exit code.
     *
     * @param list<array> $payloads Raw decoded payload arrays.
     * @return int 0 if all succeeded, 1 if any failed.
     */
    public function run(array $payloads): int
    {
        $exit_code = 0;
        $results   = [];

        foreach ($payloads as $i => $job) {
            $source    = $job['source'] ?? 'wp_cron';
            $hook      = $job['hook'];
            $job_start = microtime(true);
            $status    = 'ok';
            $error     = null;

            $this->set_alarm();

            try {
                if ($source === 'action_scheduler') {
                    $this->execute_action_scheduler($job);
                    $results[] = ['status' => 'ok', 'type' => 'as', 'action_id' => (int) ($job['action_id'] ?? 0), 'hook' => $hook];
                } else {
                    $this->execute_wp_cron($job);
                    $results[] = ['status' => 'ok', 'type' => 'cron', 'hook' => $hook];
                }
            } catch (\Throwable $e) {
                $msg    = $e->getMessage();
                $status = (stripos($msg, 'timeout') !== false) ? 'timeout' : 'error';
                $error  = $msg;
                fwrite(STDERR, "[Job $i] {$hook}: {$msg}\n");
                $results[] = ['status' => 'error', 'hook' => $hook, 'error' => $msg];
                $exit_code = 1;
            }

            $this->cancel_alarm();

            $duration_ms = (int) round((microtime(true) - $job_start) * 1000);
            Job_Log::insert(
                (int) ($job['site_id'] ?? $this->site_id),
                $hook,
                $source,
                $status,
                $duration_ms,
                $error
            );
        }

        echo json_encode($results);
        return $exit_code;
    }

    private function execute_wp_cron(array $job): void
    {
        $timestamp = (int) ($job['timestamp'] ?? 0);
        $hook      = $job['hook'];
        $args      = $job['args'] ?? [];
        $schedule  = $job['schedule'] ?? '';

        // Reschedule recurring events before firing (mirrors wp-cron.php behavior).
        // Without this, the event is simply deleted and plugins re-register it
        // at time() on next load, causing an infinite rapid-fire loop.
        if ($schedule !== '') {
            wp_reschedule_event($timestamp, $schedule, $hook, $args);
        }

        wp_unschedule_event($timestamp, $hook, $args);
        do_action_ref_array($hook, $args);
    }

    private function execute_action_scheduler(array $job): void
    {
        $action_id = (int) ($job['action_id'] ?? 0);
        if (!$action_id || !class_exists('ActionScheduler_QueueRunner')) {
            throw new \RuntimeException('ActionScheduler not available or missing action_id');
        }
        $runner = \ActionScheduler_QueueRunner::instance();
        $runner->process_action($action_id);
    }

    private function set_alarm(): void
    {
        if (!$this->has_pcntl) {
            return;
        }
        pcntl_signal(SIGALRM, function () {
            throw new \RuntimeException('Per-job timeout exceeded');
        });
        pcntl_alarm($this->job_timeout);
    }

    private function cancel_alarm(): void
    {
        if (!$this->has_pcntl) {
            return;
        }
        pcntl_alarm(0);
    }
}
