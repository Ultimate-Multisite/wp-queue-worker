<?php

namespace QueueWorker;

class Cron_Interceptor
{
    private static array $bypass_hooks = [
        'wp_version_check',
        'wp_update_plugins',
        'wp_update_themes',
        'action_scheduler_run_queue',
        'action_scheduler_run_cleanup',
    ];

    public static function register(): void
    {
        add_action('schedule_event', [__CLASS__, 'on_schedule_event']);
    }

    public static function on_schedule_event($event): void
    {
        if (!is_object($event) || empty($event->hook)) {
            return;
        }

        if (in_array($event->hook, self::$bypass_hooks, true)) {
            return;
        }

        $payload = Job_Payload::from_cron_event($event);
        Socket_Client::notify($payload);
    }
}
