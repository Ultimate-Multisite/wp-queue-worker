<?php

namespace QueueWorker;

class Action_Scheduler_Bridge
{
    public static function register(): void
    {
        // Remove the default AS queue runner — the worker handles execution
        if (class_exists('ActionScheduler_QueueRunner')) {
            remove_action(
                'action_scheduler_run_queue',
                [\ActionScheduler_QueueRunner::instance(), 'run']
            );
        }

        add_action('action_scheduler_stored_action', [__CLASS__, 'on_stored_action']);
    }

    public static function on_stored_action(int $action_id): void
    {
        $payload = Job_Payload::from_as_action($action_id);
        if ($payload === null) {
            return;
        }

        Socket_Client::notify($payload);
    }
}
