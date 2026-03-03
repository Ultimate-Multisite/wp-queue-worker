<?php

namespace QueueWorker;

class Admin_Page
{
    public static function register_menu(): void
    {
        if (is_multisite()) {
            $hook = add_submenu_page(
                'settings.php',
                'Queue Worker',
                'Queue Worker',
                'manage_network',
                'the-perfect-wp-cron',
                [__CLASS__, 'render_page']
            );
        } else {
            $hook = add_submenu_page(
                'tools.php',
                'Queue Worker',
                'Queue Worker',
                'manage_options',
                'the-perfect-wp-cron',
                [__CLASS__, 'render_page']
            );
        }

        if ($hook) {
            add_action("load-$hook", [__CLASS__, 'enqueue_assets']);
        }
    }

    public static function enqueue_assets(): void
    {
        $base = QW_PLUGIN_URL . 'assets/';
        $dir  = QW_PLUGIN_DIR . '/assets/';
        $debug = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG;

        $css = (!$debug && file_exists($dir . 'admin.min.css')) ? 'admin.min.css' : 'admin.css';
        $js  = (!$debug && file_exists($dir . 'admin.min.js'))  ? 'admin.min.js'  : 'admin.js';

        $ver = filemtime($dir . $css);

        wp_enqueue_style('qw-admin', $base . $css, [], $ver);
        wp_enqueue_script('qw-admin', $base . $js, ['jquery'], $ver, true);
        wp_localize_script('qw-admin', 'qwAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('qw_admin'),
        ]);
    }

    public static function render_page(): void
    {
        if (!self::current_user_can()) {
            wp_die('Unauthorized');
        }

        require_once __DIR__ . '/class-job-list-table.php';

        echo '<div class="wrap">';
        echo '<h1>Queue Worker</h1>';

        self::render_status_card();
        self::render_site_stats();
        self::render_job_table();
        self::render_log_viewer();

        echo '</div>';
    }

    private static function render_status_card(): void
    {
        $status = Socket_Client::is_worker_running()
            ? Socket_Client::send_command('status')
            : null;

        $stats = Job_Log::get_summary_stats(24);
        ?>
        <div class="qw-card" id="qw-status-card">
            <h2>Worker Status</h2>
            <div class="qw-status-grid">
                <div class="qw-status-left">
                    <?php if ($status): ?>
                        <span class="qw-indicator qw-indicator-running">Running</span>
                        <table class="qw-info-table">
                            <tr><th>PID</th><td id="qw-pid"><?php echo esc_html($status['pid'] ?? ''); ?></td></tr>
                            <tr><th>Uptime</th><td id="qw-uptime"><?php echo esc_html($status['uptime'] ?? ''); ?></td></tr>
                            <tr><th>Memory</th><td id="qw-memory"><?php echo esc_html($status['memory'] ?? ''); ?></td></tr>
                            <tr><th>Pending Timers</th><td id="qw-pending"><?php echo (int) ($status['pending_timers'] ?? 0); ?></td></tr>
                            <tr><th>Running Jobs</th><td id="qw-running"><?php echo (int) ($status['running_jobs'] ?? 0); ?></td></tr>
                        </table>
                        <?php if (!empty($status['running_details'])): ?>
                            <h4>Currently Executing</h4>
                            <ul class="qw-running-list" id="qw-running-details">
                                <?php foreach ($status['running_details'] as $d): ?>
                                    <li>
                                        Site <?php echo (int) $d['site_id']; ?>:
                                        <code><?php echo esc_html($d['hook']); ?></code>
                                        (<?php echo (int) $d['count']; ?> jobs, <?php echo (int) $d['elapsed']; ?>s)
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="qw-indicator qw-indicator-stopped">Stopped</span>
                        <p>The worker process is not running. Start it with:</p>
                        <code>php <?php echo esc_html(self::get_worker_path()); ?> start</code>
                    <?php endif; ?>
                </div>
                <div class="qw-status-right">
                    <h4>Last 24 Hours</h4>
                    <div class="qw-stat-cards">
                        <div class="qw-stat">
                            <span class="qw-stat-value"><?php echo number_format_i18n($stats['total']); ?></span>
                            <span class="qw-stat-label">Completed</span>
                        </div>
                        <div class="qw-stat">
                            <span class="qw-stat-value qw-stat-error"><?php echo number_format_i18n($stats['failed']); ?></span>
                            <span class="qw-stat-label">Failed</span>
                        </div>
                        <div class="qw-stat">
                            <span class="qw-stat-value"><?php echo Job_List_Table::format_duration($stats['avg_duration_ms']); ?></span>
                            <span class="qw-stat-label">Avg Duration</span>
                        </div>
                        <div class="qw-stat">
                            <span class="qw-stat-value"><?php echo esc_html($stats['error_rate']); ?>%</span>
                            <span class="qw-stat-label">Error Rate</span>
                        </div>
                    </div>
                </div>
            </div>
            <p class="qw-auto-refresh">
                <label>
                    <input type="checkbox" id="qw-auto-refresh" checked>
                    Auto-refresh every 10s
                </label>
            </p>
        </div>
        <?php
    }

    private static function render_site_stats(): void
    {
        $site_stats = Job_Log::get_site_stats(24);
        if (empty($site_stats)) {
            return;
        }
        ?>
        <div class="qw-card">
            <h2>Per-Site Resource Usage (24h)</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Jobs</th>
                        <th>Total CPU Time</th>
                        <th>Avg Duration</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($site_stats as $row): ?>
                        <tr>
                            <td>
                                <?php
                                $sid = (int) $row['site_id'];
                                if (is_multisite()) {
                                    $details = get_blog_details($sid);
                                    echo esc_html($details ? $details->blogname : "Site #$sid");
                                    echo ' <span class="qw-site-id">#' . $sid . '</span>';
                                } else {
                                    echo '#' . $sid;
                                }
                                ?>
                            </td>
                            <td><?php echo number_format_i18n((int) $row['total_jobs']); ?></td>
                            <td><?php echo esc_html(Job_List_Table::format_duration((int) $row['total_duration_ms'])); ?></td>
                            <td><?php echo esc_html(Job_List_Table::format_duration((int) round((float) $row['avg_duration_ms']))); ?></td>
                            <td>
                                <?php
                                $errors = (int) $row['error_count'];
                                if ($errors > 0) {
                                    echo '<span class="qw-status qw-status-error">' . number_format_i18n($errors) . '</span>';
                                } else {
                                    echo '0';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_job_table(): void
    {
        $table = new Job_List_Table();
        $table->prepare_items();
        ?>
        <div class="qw-card">
            <h2>Job History</h2>
            <form method="get">
                <?php if (is_multisite()): ?>
                    <input type="hidden" name="page" value="the-perfect-wp-cron">
                <?php else: ?>
                    <input type="hidden" name="page" value="the-perfect-wp-cron">
                <?php endif; ?>
                <?php
                $table->views();
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_log_viewer(): void
    {
        $log_file = Config::log_file();
        $lines = [];
        $error = '';

        if (!$log_file || !file_exists($log_file)) {
            $error = 'Log file not found. Set the <code>QUEUE_WORKER_LOG_FILE</code> constant to the path of your worker log file.';
        } elseif (!is_readable($log_file)) {
            $error = 'Log file exists but is not readable by the web server.';
        } else {
            $lines = self::tail_file($log_file, 50);
        }
        ?>
        <div class="qw-card">
            <h2>Recent Log Entries</h2>
            <?php if ($error): ?>
                <p class="qw-log-error"><?php echo wp_kses($error, ['code' => []]); ?></p>
            <?php else: ?>
                <pre class="qw-log-viewer"><?php echo esc_html(implode("\n", $lines)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function tail_file(string $path, int $count): array
    {
        try {
            $file = new \SplFileObject($path, 'r');
            $file->seek(PHP_INT_MAX);
            $total = $file->key();

            if ($total === 0) {
                return [];
            }

            $start = max(0, $total - $count);
            $lines = [];
            $file->seek($start);

            while (!$file->eof()) {
                $line = rtrim($file->fgets(), "\n\r");
                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            return $lines;
        } catch (\Throwable $e) {
            return ['Error reading log: ' . $e->getMessage()];
        }
    }

    public static function ajax_worker_status(): void
    {
        check_ajax_referer('qw_admin', 'nonce');

        if (!self::current_user_can()) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (!Socket_Client::is_worker_running()) {
            wp_send_json_success(['running' => false]);
        }

        $data = Socket_Client::send_command('status');
        if (!$data) {
            wp_send_json_success(['running' => false]);
        }

        $data['running'] = true;

        // Include 24h stats
        $data['stats'] = Job_Log::get_summary_stats(24);

        wp_send_json_success($data);
    }

    private static function current_user_can(): bool
    {
        return is_multisite()
            ? current_user_can('manage_network')
            : current_user_can('manage_options');
    }

    private static function get_worker_path(): string
    {
        // Show a relative-ish path for display
        $path = QW_PLUGIN_DIR . '/bin/worker.php';
        $abspath = defined('ABSPATH') ? ABSPATH : '';
        if ($abspath && str_starts_with($path, $abspath)) {
            return substr($path, strlen($abspath));
        }
        return $path;
    }
}
