<?php

namespace App\WebSocket;

use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeTicketService;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

class AdminRealtimeWorker
{
    private array $connections = [];
    private bool $dirty = true;

    public function __construct(
        private readonly RealtimeSnapshotService $snapshots,
        private readonly RealtimeTicketService $tickets,
    ) {
    }

    public function changed(): void
    {
        $this->dirty = true;
    }

    public function close(TcpConnection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    public function handle(TcpConnection $connection, array $message): void
    {
        $id = spl_object_id($connection);
        $event = $message['event'] ?? '';
        $data = $message['data'] ?? [];
        if (!is_array($data)) return;
        if (!isset($this->connections[$id])) {
            if ($event !== 'auth' || count($this->connections) >= 100) {
                $connection->close();
                return;
            }
            $identity = $this->tickets->consume(is_string($data['ticket'] ?? null) ? $data['ticket'] : '');
            if ($identity === null) {
                $connection->close(json_encode(['event' => 'auth.error']));
                return;
            }
            if (isset($connection->authTimer)) Timer::del($connection->authTimer);
            $connection->adminAuthenticated = true;
            $this->connections[$id] = [
                'connection' => $connection, 'identity' => $identity,
                'subscription' => null, 'subscription_id' => '', 'sequence' => 0,
                'received_at' => microtime(true), 'checked_at' => microtime(true),
                'ping_at' => 0, 'captured_at' => 0, 'hash' => null,
            ];
            $connection->send(json_encode(['event' => 'auth.success']));
            return;
        }
        $entry = &$this->connections[$id];
        $entry['received_at'] = microtime(true);
        if ($event !== 'subscribe') return;
        if (!is_string($data['subscription_id'] ?? null) || strlen($data['subscription_id']) > 80) {
            $connection->close();
            return;
        }
        $entry['subscription'] = $this->snapshots->validate($data);
        $entry['subscription_id'] = $data['subscription_id'];
        $entry['hash'] = null;
        $entry['captured_at'] = 0;
        $this->dirty = true;
    }

    /** 状态变化优先推送，定时检查用于过期显示、结算结果及丢失通知后的恢复。 */
    public function tick(): void
    {
        $now = microtime(true);
        $frames = [];
        $dirty = $this->dirty;
        $this->dirty = false;
        foreach (array_keys($this->connections) as $id) {
            if (!isset($this->connections[$id])) continue;
            $entry = &$this->connections[$id];
            $connection = $entry['connection'];
            try {
                if ($now - $entry['received_at'] > 20) {
                    $connection->close();
                    unset($this->connections[$id]);
                    continue;
                }
                if ($now - $entry['checked_at'] >= 10) {
                    $entry['checked_at'] = $now;
                    if (!$this->tickets->isValid($entry['identity'])) {
                        $connection->close(json_encode(['event' => 'auth.error']));
                        unset($this->connections[$id]);
                        continue;
                    }
                }
                if ($now - $entry['ping_at'] >= 5) {
                    $connection->send(json_encode(['event' => 'ping']));
                    $entry['ping_at'] = $now;
                }
                if ($entry['subscription'] === null || (!$dirty && $now - $entry['captured_at'] < 1)) continue;
                if ($now - $entry['captured_at'] < 0.1) {
                    $this->dirty = true;
                    continue;
                }
                $entry['captured_at'] = $now;
                $key = json_encode($entry['subscription'], JSON_THROW_ON_ERROR);
                $frame = $frames[$key] ??= $this->snapshots->snapshot($entry['subscription']);
                // 捕获版本只用于新旧排序，不能让未变化的展示内容每次都被视为变化。
                $content = $frame;
                unset($content['version']);
                $hash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
                if ($hash === $entry['hash']) continue;
                $entry['hash'] = $hash;
                $entry['sequence']++;
                $sent = $connection->send(json_encode(['event' => 'state.snapshot', 'data' => $frame + [
                    'subscription_id' => $entry['subscription_id'], 'sequence' => $entry['sequence'],
                ]], JSON_THROW_ON_ERROR));
                if ($sent === false) {
                    $connection->close();
                    unset($this->connections[$id]);
                }
            } catch (\Throwable) {
                // 读取失败不伪装成零值；关闭通道让浏览器回退到有错误状态的 HTTP。
                $connection->close(json_encode(['event' => 'state.error']));
                unset($this->connections[$id]);
            }
        }
    }
}
