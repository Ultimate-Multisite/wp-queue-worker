<?php
/**
 * Plugin Name: WP Queue Worker
 * Plugin URI: https://github.com/Ultimate-Multisite/wp-queue-worker
 * Description: Event-loop job queue for WordPress. Executes WP Cron and Action Scheduler jobs at exact scheduled times with zero polling using Workerman.
 * Version: 1.0.0
 * Author: David Stone
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    return;
}

// Don't register interceptors inside the worker process itself
if (defined('QUEUE_WORKER_RUNNING') && QUEUE_WORKER_RUNNING) {
    return;
}

require_once __DIR__ . '/src/class-job-payload.php';
require_once __DIR__ . '/src/class-socket-client.php';
require_once __DIR__ . '/src/class-cron-interceptor.php';
require_once __DIR__ . '/src/class-action-scheduler-bridge.php';
require_once __DIR__ . '/src/class-cli-commands.php';

add_action('init', ['QueueWorker\\Cron_Interceptor', 'register']);
add_action('action_scheduler_init', ['QueueWorker\\Action_Scheduler_Bridge', 'register']);

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('queue', 'QueueWorker\\CLI_Commands');
}
