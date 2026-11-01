<?php

namespace App\Services;

class FrontendHealthChecker
{
    /** 并发探测，单个坏端口不会让整个池逐台等待超时。 */
    public function reachable(array $addresses, int $port): array
    {
        $pending = []; $healthy = [];
        foreach (array_unique($addresses) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) continue;
            $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $socket = @stream_socket_client('tcp://' . $target . ':' . $port, $code, $error, 0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket) { stream_set_blocking($socket, false); $pending[(int) $socket] = [$socket, $address]; }
        }
        $deadline = microtime(true) + 5;
        try {
            while ($pending && microtime(true) < $deadline) {
                $write = array_column($pending, 0); $read = null; $except = $write;
                if (@stream_select($read, $write, $except, 0, 100000) === false) break;
                foreach (array_merge($write, $except) as $socket) {
                    $key = (int) $socket;
                    if (!isset($pending[$key])) continue;
                    if (@stream_socket_get_name($socket, true) !== false) $healthy[] = $pending[$key][1];
                    fclose($socket); unset($pending[$key]);
                }
            }
        } finally { foreach ($pending as [$socket]) fclose($socket); }
        return $healthy;
    }
}
