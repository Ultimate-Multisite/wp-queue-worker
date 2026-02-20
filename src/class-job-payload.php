<?php

namespace QueueWorker;

class Job_Payload
{
    public int $site_id;
    public string $site_url;
    public string $hook;
    public array $args;
    public int $timestamp;
    public string $schedule;
    public int $interval;
    public string $source; // 'wp_cron' | 'action_scheduler'
    public int $action_id;

    public function __construct(array $data = [])
    {
        $this->site_id   = $data['site_id'] ?? get_current_blog_id();
        $this->site_url  = $data['site_url'] ?? get_site_url();
        $this->hook      = $data['hook'] ?? '';
        $this->args      = $data['args'] ?? [];
        $this->timestamp = $data['timestamp'] ?? 0;
        $this->schedule  = $data['schedule'] ?? '';
        $this->interval  = $data['interval'] ?? 0;
        $this->source    = $data['source'] ?? 'wp_cron';
        $this->action_id = $data['action_id'] ?? 0;
    }

    public function to_json(): string
    {
        return json_encode([
            'site_id'   => $this->site_id,
            'site_url'  => $this->site_url,
            'hook'      => $this->hook,
            'args'      => $this->args,
            'timestamp' => $this->timestamp,
            'schedule'  => $this->schedule,
            'interval'  => $this->interval,
            'source'    => $this->source,
            'action_id' => $this->action_id,
        ]);
    }

    public static function from_json(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON payload');
        }
        return new self($data);
    }

    public static function from_cron_event(object $event): self
    {
        $schedules = wp_get_schedules();
        $interval  = 0;
        if (!empty($event->schedule) && isset($schedules[$event->schedule])) {
            $interval = (int) $schedules[$event->schedule]['interval'];
        }

        return new self([
            'site_id'   => get_current_blog_id(),
            'site_url'  => get_site_url(),
            'hook'      => $event->hook,
            'args'      => $event->args ?? [],
            'timestamp' => (int) $event->timestamp,
            'schedule'  => $event->schedule ?? '',
            'interval'  => $interval,
            'source'    => 'wp_cron',
        ]);
    }

    public static function from_as_action(int $action_id): ?self
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return null;
        }

        $store  = \ActionScheduler::store();
        $action = $store->fetch_action($action_id);
        if ($action->is_finished()) {
            return null;
        }

        $schedule  = $action->get_schedule();
        $next_date = $schedule->get_date();
        $timestamp = $next_date ? $next_date->getTimestamp() : time();

        return new self([
            'site_id'   => get_current_blog_id(),
            'site_url'  => get_site_url(),
            'hook'      => $action->get_hook(),
            'args'      => $action->get_args(),
            'timestamp' => $timestamp,
            'source'    => 'action_scheduler',
            'action_id' => $action_id,
        ]);
    }

    public function tracking_key(): string
    {
        return sprintf(
            '%s:%d:%s:%s:%d',
            $this->source,
            $this->site_id,
            $this->hook,
            md5(serialize($this->args)),
            $this->timestamp
        );
    }
}
