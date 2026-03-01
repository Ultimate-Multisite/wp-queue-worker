<?php

namespace QueueWorker;

class Config
{
    public static function socket_path(): string
    {
        return self::get('QUEUE_WORKER_SOCKET_PATH', '/tmp/wp-queue-worker.sock');
    }

    public static function worker_count(): int
    {
        return (int) self::get('QUEUE_WORKER_COUNT', 2);
    }

    public static function max_concurrent(): int
    {
        return (int) self::get('QUEUE_WORKER_MAX_CONCURRENT', 1);
    }

    public static function max_batch_size(): int
    {
        return (int) self::get('QUEUE_WORKER_MAX_BATCH_SIZE', 50);
    }

    public static function job_timeout(): int
    {
        return (int) self::get('QUEUE_WORKER_JOB_TIMEOUT', 300);
    }

    public static function batch_timeout(): int
    {
        return (int) self::get('QUEUE_WORKER_BATCH_TIMEOUT', 3600);
    }

    public static function rescan_interval(): int
    {
        return (int) self::get('QUEUE_WORKER_RESCAN_INTERVAL', 60);
    }

    public static function memory_limit(): int
    {
        return (int) self::get('QUEUE_WORKER_MEMORY_LIMIT', 200);
    }

    public static function uptime_limit(): int
    {
        return (int) self::get('QUEUE_WORKER_UPTIME_LIMIT', 3600);
    }

    public static function log_file(): string
    {
        $value = self::get('QUEUE_WORKER_LOG_FILE', '');
        if ($value !== '') {
            return $value;
        }
        // Auto-detect: Workerman default log location
        if (defined('ABSPATH')) {
            $dir = WP_CONTENT_DIR . '/logs';
            if (is_dir($dir) && is_writable($dir)) {
                return $dir . '/wp-queue-worker.log';
            }
        }
        return '/var/log/wp-queue-worker.log';
    }

    public static function log_retention(): int
    {
        return (int) self::get('QUEUE_WORKER_LOG_RETENTION', 7);
    }

    private static function get(string $name, mixed $default): mixed
    {
        // PHP constant first
        if (defined($name)) {
            return constant($name);
        }
        // Environment variable
        $env = getenv($name);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $default;
    }
}
