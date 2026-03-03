<?php

namespace QueueWorker;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Job_List_Table extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'job',
            'plural'   => 'jobs',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'site_id'      => 'Site',
            'hook'         => 'Hook',
            'source'       => 'Source',
            'status'       => 'Status',
            'duration_ms'  => 'Duration',
            'error_msg'    => 'Error',
            'completed_at' => 'Completed',
        ];
    }

    public function get_sortable_columns(): array
    {
        return [
            'completed_at' => ['completed_at', true], // default DESC
            'duration_ms'  => ['duration_ms', false],
            'site_id'      => ['site_id', false],
            'hook'         => ['hook', false],
        ];
    }

    protected function get_views(): array
    {
        $current_status = sanitize_text_field($_REQUEST['status'] ?? '');
        $base_url = $this->get_base_url();

        $total  = Job_Log::count();
        $ok     = Job_Log::count(['status' => 'ok']);
        $error  = Job_Log::count(['status' => 'error']);
        $timeout = Job_Log::count(['status' => 'timeout']);

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s" class="%s">All <span class="count">(%s)</span></a>',
            esc_url($base_url),
            $current_status === '' ? 'current' : '',
            number_format_i18n($total)
        );
        if ($ok > 0) {
            $views['ok'] = sprintf(
                '<a href="%s" class="%s">OK <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('status', 'ok', $base_url)),
                $current_status === 'ok' ? 'current' : '',
                number_format_i18n($ok)
            );
        }
        if ($error > 0) {
            $views['error'] = sprintf(
                '<a href="%s" class="%s">Error <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('status', 'error', $base_url)),
                $current_status === 'error' ? 'current' : '',
                number_format_i18n($error)
            );
        }
        if ($timeout > 0) {
            $views['timeout'] = sprintf(
                '<a href="%s" class="%s">Timeout <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('status', 'timeout', $base_url)),
                $current_status === 'timeout' ? 'current' : '',
                number_format_i18n($timeout)
            );
        }

        return $views;
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $current_site   = sanitize_text_field($_REQUEST['filter_site'] ?? '');
        $current_source = sanitize_text_field($_REQUEST['filter_source'] ?? '');

        echo '<div class="alignleft actions">';

        // Site filter
        if (is_multisite()) {
            $sites = get_sites(['number' => 100, 'fields' => 'ids']);
            echo '<select name="filter_site">';
            echo '<option value="">All Sites</option>';
            foreach ($sites as $sid) {
                $name = get_blog_details($sid)->blogname ?? "Site #$sid";
                printf(
                    '<option value="%d" %s>%s (#%d)</option>',
                    $sid,
                    selected($current_site, $sid, false),
                    esc_html($name),
                    $sid
                );
            }
            echo '</select>';
        }

        // Source filter
        echo '<select name="filter_source">';
        echo '<option value="">All Sources</option>';
        printf('<option value="wp_cron" %s>WP Cron</option>', selected($current_source, 'wp_cron', false));
        printf('<option value="action_scheduler" %s>Action Scheduler</option>', selected($current_source, 'action_scheduler', false));
        echo '</select>';

        submit_button('Filter', '', 'filter_action', false);
        echo '</div>';
    }

    public function prepare_items(): void
    {
        $per_page = 50;
        $current_page = $this->get_pagenum();

        $args = [
            'per_page' => $per_page,
            'offset'   => ($current_page - 1) * $per_page,
            'orderby'  => sanitize_text_field($_REQUEST['orderby'] ?? 'completed_at'),
            'order'    => sanitize_text_field($_REQUEST['order'] ?? 'DESC'),
        ];

        $status = sanitize_text_field($_REQUEST['status'] ?? '');
        if ($status !== '') {
            $args['status'] = $status;
        }

        $filter_site = (int) ($_REQUEST['filter_site'] ?? 0);
        if ($filter_site > 0) {
            $args['site_id'] = $filter_site;
        }

        $filter_source = sanitize_text_field($_REQUEST['filter_source'] ?? '');
        if ($filter_source !== '') {
            $args['source'] = $filter_source;
        }

        $this->items = Job_Log::query($args);
        $total_items = Job_Log::count($args);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    protected function column_default($item, $column_name): string
    {
        return esc_html($item[$column_name] ?? '');
    }

    protected function column_site_id($item): string
    {
        $site_id = (int) $item['site_id'];
        if (is_multisite()) {
            $details = get_blog_details($site_id);
            $name = $details ? $details->blogname : "Site";
            return sprintf('%s <span class="qw-site-id">#%d</span>', esc_html($name), $site_id);
        }
        return sprintf('#%d', $site_id);
    }

    protected function column_hook($item): string
    {
        return '<code>' . esc_html($item['hook']) . '</code>';
    }

    protected function column_source($item): string
    {
        $source = $item['source'] ?? 'wp_cron';
        return $source === 'action_scheduler'
            ? '<span class="qw-source qw-source-as">AS</span>'
            : '<span class="qw-source qw-source-cron">Cron</span>';
    }

    protected function column_status($item): string
    {
        $status = $item['status'] ?? 'ok';
        $class = match ($status) {
            'ok'      => 'qw-status-ok',
            'error'   => 'qw-status-error',
            'timeout' => 'qw-status-timeout',
            default   => '',
        };
        return sprintf('<span class="qw-status %s">%s</span>', $class, esc_html($status));
    }

    protected function column_duration_ms($item): string
    {
        return self::format_duration((int) $item['duration_ms']);
    }

    protected function column_error_msg($item): string
    {
        $msg = $item['error_msg'] ?? '';
        if ($msg === '' || $msg === null) {
            return '&mdash;';
        }
        $short = mb_strlen($msg) > 80 ? mb_substr($msg, 0, 80) . '...' : $msg;
        return sprintf(
            '<span class="qw-error" title="%s">%s</span>',
            esc_attr($msg),
            esc_html($short)
        );
    }

    protected function column_completed_at($item): string
    {
        $time = strtotime($item['completed_at'] . ' UTC');
        if (!$time) {
            return esc_html($item['completed_at']);
        }
        return sprintf(
            '<span title="%s">%s</span>',
            esc_attr(wp_date('Y-m-d H:i:s', $time)),
            esc_html(human_time_diff($time) . ' ago')
        );
    }

    public static function format_duration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        if ($ms < 60000) {
            return round($ms / 1000, 1) . 's';
        }
        $mins = floor($ms / 60000);
        $secs = round(($ms % 60000) / 1000);
        return sprintf('%dm %ds', $mins, $secs);
    }

    private function get_base_url(): string
    {
        if (is_multisite()) {
            return network_admin_url('settings.php?page=the-perfect-wp-cron');
        }
        return admin_url('tools.php?page=the-perfect-wp-cron');
    }
}
