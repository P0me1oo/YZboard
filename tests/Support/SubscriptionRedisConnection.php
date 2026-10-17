<?php

namespace Tests\Support;

/** 仅测试使用的本机 RESP 连接；不增加生产依赖，不读取部署 Redis 设置。 */
class SubscriptionRedisConnection
{
    private $socket;
    public bool $failAck = false;
    private array $keys = [];

    public function __construct(private readonly int $port, private readonly string $prefix)
    {
        $this->socket = stream_socket_client('tcp://127.0.0.1:' . $port, $code, $message, 3);
        if (!$this->socket) throw new \RuntimeException('专用 Redis 测试实例不可用');
        stream_set_timeout($this->socket, 3);
    }

    public function eval(string $script, int $count, ...$arguments): mixed
    {
        if ($this->failAck && str_contains($script, "return redis.call('DEL'")) {
            $this->failAck = false;
            throw new \RuntimeException('模拟提交后未确认');
        }
        for ($i = 0; $i < $count; $i++) {
            $arguments[$i] = $this->prefix . $arguments[$i];
            $this->keys[$arguments[$i]] = true;
        }
        return $this->command('EVAL', $script, $count, ...$arguments);
    }

    public function __call(string $name, array $arguments): mixed
    {
        $arguments[0] = $this->prefix . $arguments[0];
        $this->keys[$arguments[0]] = true;
        return $this->command(strtoupper($name), ...$arguments);
    }

    public function close(): void
    {
        if (!is_resource($this->socket)) return;
        if ($this->keys) $this->command('DEL', ...array_keys($this->keys));
        fclose($this->socket);
    }

    private function command(...$parts): mixed
    {
        $wire = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) $wire .= '$' . strlen((string) $part) . "\r\n" . $part . "\r\n";
        for ($offset = 0; $offset < strlen($wire);) {
            $written = fwrite($this->socket, substr($wire, $offset));
            if (!$written) throw new \RuntimeException('Redis 测试连接写入失败');
            $offset += $written;
        }
        return $this->read();
    }

    private function read(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) throw new \RuntimeException('Redis 测试响应超时');
        $value = substr($line, 1, -2);
        if ($line[0] === '-') throw new \RuntimeException('Redis 测试命令失败');
        if ($line[0] === ':') return (int) $value;
        if ($line[0] === '$') {
            if ((int) $value < 0) return null;
            $body = '';
            while (strlen($body) < (int) $value + 2) {
                $part = fread($this->socket, (int) $value + 2 - strlen($body));
                if ($part === '' || $part === false) throw new \RuntimeException('Redis 测试响应截断');
                $body .= $part;
            }
            return substr($body, 0, -2);
        }
        return $value;
    }
}
