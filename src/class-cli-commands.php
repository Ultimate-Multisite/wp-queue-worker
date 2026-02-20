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
        $socket_path = Socket_Client::get_socket_path();

        if (!file_exists($socket_path)) {
            WP_CLI::warning('Queue worker is not running (no socket file).');
            return;
        }

        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 2);
        if (!$socket) {
            WP_CLI::warning("Socket exists but worker is not responding: $errstr");
            return;
        }

        fwrite($socket, json_encode(['command' => 'status']) . "\n");

        // Wait for response with timeout
        stream_set_timeout($socket, 5);
        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            WP_CLI::warning('Worker did not respond to status request.');
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            WP_CLI::warning('Invalid response from worker.');
            return;
        }

        WP_CLI::success('Queue worker is running.');
        WP_CLI::log(sprintf('  PID:            %d', $data['pid'] ?? 0));
        WP_CLI::log(sprintf('  Uptime:         %s', $data['uptime'] ?? 'unknown'));
        WP_CLI::log(sprintf('  Pending timers: %d', $data['pending_timers'] ?? 0));
        WP_CLI::log(sprintf('  Running jobs:   %d', $data['running_jobs'] ?? 0));
        WP_CLI::log(sprintf('  Memory:         %s', $data['memory'] ?? 'unknown'));
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
        $socket_path = Socket_Client::get_socket_path();

        if (!file_exists($socket_path)) {
            WP_CLI::error('Queue worker is not running.');
        }

        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 2);
        if (!$socket) {
            WP_CLI::error("Cannot connect to worker: $errstr");
        }

        fwrite($socket, json_encode(['command' => 'restart']) . "\n");
        fclose($socket);

        WP_CLI::success('Sent restart signal to queue worker. systemd will restart it.');
    }
}
