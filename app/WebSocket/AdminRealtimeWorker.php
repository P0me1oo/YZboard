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
    private array $frames = [];

    public function __construct(
        private readonly RealtimeSnapshotService $snapshots,
        private readonly RealtimeTicketService $tickets,
        private readonly ?\Closure $clock = null,
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
                'received_at' => $this->now(), 'checked_at' => $this->now(),
                'ping_at' => 0, 'captured_at' => 0, 'hash' => null,
            ];
            $connection->send(json_encode(['event' => 'auth.success']));
            return;
        }
        $entry = &$this->connections[$id];
        $entry['received_at'] = $this->now();
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

    /** 每秒合并变化，同一订阅共享快照和内容摘要；首次订阅仍在下一次调度时响应。 */
    public function tick(): void
    {
        $now = $this->now();
        $active = [];
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
                if ($entry['subscription'] === null) continue;
                $key = json_encode($entry['subscription'], JSON_THROW_ON_ERROR);
                $active[$key] = true;
                if (!$dirty && $now - $entry['captured_at'] < 1) continue;
                if ($now - $entry['captured_at'] < 1) {
                    $this->dirty = true;
                    continue;
                }
                $cached = $this->frames[$key] ?? null;
                if ($cached === null || $now - $cached['captured_at'] >= 1) {
                    $frame = $this->snapshots->snapshot($entry['subscription']);
                    // 捕获版本只用于新旧排序，不能让未变化的展示内容每次都被视为变化。
                    $content = $frame;
                    unset($content['version']);
                    $cached = $this->frames[$key] = [
                        'frame' => $frame, 'captured_at' => $now,
                        'hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
                    ];
                }
                $entry['captured_at'] = $cached['captured_at'];
                $frame = $cached['frame'];
                $hash = $cached['hash'];
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
        // 只保留当前订阅，切换页面不会让常驻进程积累旧查询结果。
        $this->frames = array_intersect_key($this->frames, $active);
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}
