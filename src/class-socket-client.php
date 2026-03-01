<?php

namespace QueueWorker;

class Socket_Client
{
    public static function get_socket_path(): string
    {
        return Config::socket_path();
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

    public static function send_command(string $command, int $timeout = 5): ?array
    {
        $path = self::get_socket_path();
        $socket = @stream_socket_client('unix://' . $path, $errno, $errstr, 2);
        if (!$socket) {
            return null;
        }

        fwrite($socket, json_encode(['command' => $command]) . "\n");
        stream_set_timeout($socket, $timeout);
        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    public static function is_worker_running(): bool
    {
        return file_exists(self::get_socket_path());
    }
}
