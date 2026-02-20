<?php

namespace QueueWorker;

class Socket_Client
{
    public static function get_socket_path(): string
    {
        if (defined('QUEUE_WORKER_SOCKET_PATH')) {
            return QUEUE_WORKER_SOCKET_PATH;
        }
        return '/tmp/wp-queue-worker.sock';
    }

    public static function notify(Job_Payload $payload): bool
    {
        $path = self::get_socket_path();
        $socket = @stream_socket_client('unix://' . $path, $errno, $errstr, 1);
        if (!$socket) {
            return false;
        }
        fwrite($socket, $payload->to_json() . "\n");
        fclose($socket);
        return true;
    }

    public static function is_worker_running(): bool
    {
        return file_exists(self::get_socket_path());
    }
}
