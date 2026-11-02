<?php

namespace App\Services;

/** 并发建立 TCP 连接，只检查配置中的 IP 与公网端口，不触碰代理凭据。 */
class FrontPortProbe
{
    public function check(array $addresses, int $port): array
    {
        $sockets = []; $results = []; $starts = [];
        foreach (array_unique($addresses) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $starts[$ip] = microtime(true);
            $socket = @stream_socket_client('tcp://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port,
                $errno, $error, 2, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket === false) $results[$ip] = ['ok' => false, 'rtt_ms' => null];
            else { stream_set_blocking($socket, false); $sockets[$ip] = $socket; }
        }
        $deadline = microtime(true) + 2;
        while ($sockets && microtime(true) < $deadline) {
            $write = array_values($sockets); $read = null; $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) === false) break;
            foreach ($sockets as $ip => $socket) {
                if (!in_array($socket, $write, true)) continue;
                $ok = @stream_socket_get_name($socket, true) !== false;
                $results[$ip] = ['ok' => $ok, 'rtt_ms' => $ok ? (int) round((microtime(true) - $starts[$ip]) * 1000) : null];
                fclose($socket); unset($sockets[$ip]);
            }
        }
        foreach ($sockets as $ip => $socket) { fclose($socket); $results[$ip] = ['ok' => false, 'rtt_ms' => null]; }
        return $results;
    }
}
