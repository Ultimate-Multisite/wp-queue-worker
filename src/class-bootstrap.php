<?php

namespace QueueWorker;

/**
 * Shared bootstrap helpers for CLI entry points.
 *
 * Eliminates duplicated autoloader/wp-load/dotenv discovery code
 * between worker.php and execute-job.php.
 */
class Bootstrap
{
    /**
     * Walk up directories from $start_dir looking for vendor/autoload.php.
     *
     * @param string $start_dir Directory to start searching from.
     * @return string|null Absolute path to autoload.php, or null if not found.
     */
    public static function discover_autoload(string $start_dir): ?string
    {
        $dir = $start_dir;
        for ($i = 0; $i < 10; $i++) {
            $dir = dirname($dir);
            $candidate = $dir . '/vendor/autoload.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Discover wp-load.php.
     *
     * Checks WP_ROOT_PATH env var first, then walks up directories looking
     * for wp-load.php (standard) or web/wp/wp-load.php (Bedrock).
     *
     * @param string $start_dir Directory to start searching from.
     * @return string Absolute path to wp-load.php.
     */
    public static function discover_wp_load(string $start_dir): string
    {
        // Check environment variable first
        $root = getenv('WP_ROOT_PATH');
        if ($root) {
            $root = rtrim($root, '/');
            if (file_exists($root . '/wp-load.php')) {
                return $root . '/wp-load.php';
            }
        }

        // Walk up directories
        $dir = $start_dir;
        for ($i = 0; $i < 10; $i++) {
            $dir = dirname($dir);
            if (file_exists($dir . '/wp-load.php')) {
                return $dir . '/wp-load.php';
            }
            // Bedrock: web/wp/wp-load.php
            if (file_exists($dir . '/web/wp/wp-load.php')) {
                return $dir . '/web/wp/wp-load.php';
            }
        }

        fwrite(STDERR, "ERROR: Could not find wp-load.php. Set WP_ROOT_PATH environment variable.\n");
        exit(1);
    }

    /**
     * Load .env file(s) if Dotenv is available (Bedrock support).
     *
     * @param string $site_root Root directory containing .env file.
     */
    public static function load_dotenv(string $site_root): void
    {
        if (!class_exists('Dotenv\\Dotenv') || !file_exists($site_root . '/.env')) {
            return;
        }

        $env_files = file_exists($site_root . '/.env.local')
            ? ['.env', '.env.local']
            : ['.env'];

        $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($site_root, $env_files, false);
        $dotenv->load();
    }
}
