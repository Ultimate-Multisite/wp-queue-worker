<?php

namespace QueueWorker;

use WP_CLI;

class CLI_Commands
{
    /**
     * Show queue worker status.
     *
     * ## EXAMPLES
     *
     *     wp queue status
     *
     * @subcommand status
     */
    public function status($args, $assoc_args): void
    {
        if (!Socket_Client::is_worker_running()) {
            WP_CLI::warning('Queue worker is not running (no socket file).');
            return;
        }

        $data = Socket_Client::send_command('status');
        if (!$data) {
            WP_CLI::warning('Worker did not respond to status request.');
            return;
        }

        WP_CLI::success('Queue worker is running.');
        WP_CLI::log(sprintf('  PID:            %d', $data['pid'] ?? 0));
        WP_CLI::log(sprintf('  Uptime:         %s', $data['uptime'] ?? 'unknown'));
        WP_CLI::log(sprintf('  Pending timers: %d', $data['pending_timers'] ?? 0));
        WP_CLI::log(sprintf('  Running jobs:   %d', $data['running_jobs'] ?? 0));
        WP_CLI::log(sprintf('  Memory:         %s', $data['memory'] ?? 'unknown'));

        if (!empty($data['running_details'])) {
            WP_CLI::log('  Currently executing:');
            foreach ($data['running_details'] as $detail) {
                WP_CLI::log(sprintf(
                    '    - site %d: %s (%d jobs, %ds elapsed)',
                    $detail['site_id'],
                    $detail['hook'],
                    $detail['count'],
                    $detail['elapsed']
                ));
            }
        }
    }

    /**
     * Force rescan of all pending jobs and send to worker.
     *
     * ## EXAMPLES
     *
     *     wp queue populate
     *
     * @subcommand populate
     */
    public function populate($args, $assoc_args): void
    {
        if (!Socket_Client::is_worker_running()) {
            WP_CLI::error('Queue worker is not running.');
        }

        $count = 0;

        // Scan WP Cron events across all sites
        $sites = get_sites(['number' => 0, 'fields' => 'ids']);
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);

            $crons = _get_cron_array();
            if (is_array($crons)) {
                foreach ($crons as $timestamp => $hooks) {
                    foreach ($hooks as $hook => $events) {
                        foreach ($events as $key => $event) {
                            $event_obj = (object) array_merge($event, [
                                'hook'      => $hook,
                                'timestamp' => $timestamp,
                            ]);
                            $payload = Job_Payload::from_cron_event($event_obj);
                            if (Socket_Client::notify($payload)) {
                                $count++;
                            }
                        }
                    }
                }
            }

            // Scan Action Scheduler pending actions
            if (function_exists('as_get_scheduled_actions')) {
                $actions = as_get_scheduled_actions([
                    'status'   => \ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => 500,
                ]);
                foreach ($actions as $action_id => $action) {
                    $payload = Job_Payload::from_as_action($action_id);
                    if ($payload && Socket_Client::notify($payload)) {
                        $count++;
                    }
                }
            }

            restore_current_blog();
        }

        WP_CLI::success("Sent $count jobs to the queue worker.");
    }

    /**
     * Restart the queue worker (sends SIGTERM, systemd auto-restarts).
     *
     * ## EXAMPLES
     *
     *     wp queue restart
     *
     * @subcommand restart
     */
    public function restart($args, $assoc_args): void
    {
        if (!Socket_Client::is_worker_running()) {
            WP_CLI::error('Queue worker is not running.');
        }

        // send_command won't get a response since worker stops immediately
        $path = Socket_Client::get_socket_path();
        $socket = @stream_socket_client('unix://' . $path, $errno, $errstr, 2);
        if (!$socket) {
            WP_CLI::error("Cannot connect to worker: $errstr");
        }
        fwrite($socket, json_encode(['command' => 'restart']) . "\n");
        fclose($socket);

        WP_CLI::success('Sent restart signal to queue worker. systemd will restart it.');
    }
}
