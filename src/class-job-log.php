<?php

namespace QueueWorker;

class Job_Log
{
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'qw_job_log';
    }

    public static function ensure_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            hook VARCHAR(255) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'wp_cron',
            status VARCHAR(10) NOT NULL DEFAULT 'ok',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            error_msg TEXT NULL,
            completed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY site_hook (site_id, hook),
            KEY status (status),
            KEY completed_at (completed_at)
        ) ENGINE=InnoDB $charset";

        $wpdb->query($sql);
    }

    public static function insert(
        int $site_id,
        string $hook,
        string $source,
        string $status,
        int $duration_ms,
        ?string $error_msg = null
    ): void {
        global $wpdb;
        $table = self::table();

        $wpdb->insert($table, [
            'site_id'      => $site_id,
            'hook'         => $hook,
            'source'       => $source,
            'status'       => $status,
            'duration_ms'  => $duration_ms,
            'error_msg'    => $error_msg,
            'completed_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);
    }

    public static function query(array $args = []): array
    {
        global $wpdb;
        $table = self::table();

        $defaults = [
            'site_id'  => 0,
            'source'   => '',
            'status'   => '',
            'hook'     => '',
            'orderby'  => 'completed_at',
            'order'    => 'DESC',
            'per_page' => 50,
            'offset'   => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = [];
        $values = [];

        if ($args['site_id'] > 0) {
            $where[] = 'site_id = %d';
            $values[] = $args['site_id'];
        }
        if ($args['source'] !== '') {
            $where[] = 'source = %s';
            $values[] = $args['source'];
        }
        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        if ($args['hook'] !== '') {
            $where[] = 'hook LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['hook']) . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowed_orderby = ['completed_at', 'duration_ms', 'site_id', 'hook', 'status'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'completed_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM `$table` $where_sql ORDER BY `$orderby` $order LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $args['offset'];

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function count(array $args = []): int
    {
        global $wpdb;
        $table = self::table();

        $where = [];
        $values = [];

        if (!empty($args['site_id'])) {
            $where[] = 'site_id = %d';
            $values[] = (int) $args['site_id'];
        }
        if (!empty($args['source'])) {
            $where[] = 'source = %s';
            $values[] = $args['source'];
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        if (!empty($args['hook'])) {
            $where[] = 'hook LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['hook']) . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) FROM `$table` $where_sql";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function get_summary_stats(int $hours = 24): array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status != 'ok' THEN 1 ELSE 0 END) as failed,
                AVG(duration_ms) as avg_duration_ms,
                MAX(duration_ms) as max_duration_ms
            FROM `$table`
            WHERE completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
            $hours
        ), ARRAY_A);

        if (!$row) {
            return ['total' => 0, 'failed' => 0, 'avg_duration_ms' => 0, 'max_duration_ms' => 0, 'error_rate' => 0];
        }

        $total = (int) $row['total'];
        $failed = (int) $row['failed'];

        return [
            'total'           => $total,
            'failed'          => $failed,
            'avg_duration_ms' => (int) round((float) $row['avg_duration_ms']),
            'max_duration_ms' => (int) $row['max_duration_ms'],
            'error_rate'      => $total > 0 ? round($failed / $total * 100, 1) : 0,
        ];
    }

    public static function get_site_stats(int $hours = 24): array
    {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                site_id,
                COUNT(*) as total_jobs,
                SUM(duration_ms) as total_duration_ms,
                AVG(duration_ms) as avg_duration_ms,
                SUM(CASE WHEN status != 'ok' THEN 1 ELSE 0 END) as error_count
            FROM `$table`
            WHERE completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
            GROUP BY site_id
            ORDER BY total_duration_ms DESC",
            $hours
        ), ARRAY_A) ?: [];
    }

    public static function cleanup(int $days = 0): int
    {
        global $wpdb;
        $table = self::table();

        if ($days <= 0) {
            $days = Config::log_retention();
        }

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `$table` WHERE completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        ));
    }
}
