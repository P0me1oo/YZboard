<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Plugin\HookManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/** 只供状态上报与设备同步复用资料；认证、配置下发和流量结算仍直接查询数据库。 */
class NodeRuntimeMetadata
{
    private const TTL_MS = 5000;
    private const MAX_ENTRIES = 1024;
    private array $entries = [];

    public function node(int $id): ?Server
    {
        return $this->find(Server::class, $id);
    }

    public function nodeOrFail(int $id): Server
    {
        return $this->node($id) ?? throw (new ModelNotFoundException())->setModel(Server::class, [$id]);
    }

    public function nodeForDevices(int $id): ?Server
    {
        // 用户筛选插件可能读取节点的累计流量等任意字段，保留其原有实时查询语义。
        return isset(HookManager::getFilters()['server.users.get']) ? Server::find($id) : $this->node($id);
    }

    public function machineOrFail(int $id): ServerMachine
    {
        return $this->find(ServerMachine::class, $id)
            ?? throw (new ModelNotFoundException())->setModel(ServerMachine::class, [$id]);
    }

    /** 提交后换代，避免事务回滚或并发读把旧资料重新留在有效缓存里。 */
    public static function invalidate(string $modelClass): void
    {
        $connection = (new $modelClass())->getConnection();
        $invalidate = fn () => Cache::forever(self::revisionKey($modelClass), bin2hex(random_bytes(16)));
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($invalidate);
        } else {
            $invalidate();
        }
    }

    private function find(string $modelClass, int $id): ?Model
    {
        // 事务内部必须看本事务的数据，也不能把未提交的模型放进常驻缓存。
        if ((new $modelClass())->getConnection()->transactionLevel() > 0) {
            return $modelClass::find($id);
        }
        try {
            $key = self::revisionKey($modelClass);
            $revision = Cache::get($key);
            if (!is_string($revision)) {
                Cache::add($key, bin2hex(random_bytes(16)), 86400);
                $revision = Cache::get($key);
            }
            if (!is_string($revision)) {
                return $modelClass::find($id);
            }
        } catch (\Throwable) {
            // 无法确认版本时查询数据库，不继续使用旧资料。
            return $modelClass::find($id);
        }

        $key = $modelClass . ':' . $id;
        $now = now()->getTimestampMs();
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || $entry['revision'] !== $revision || $now >= $entry['expires_at']) {
            $model = $modelClass::find($id);
            if (count($this->entries) >= self::MAX_ENTRIES && !array_key_exists($key, $this->entries)) {
                array_shift($this->entries);
            }
            $entry = $this->entries[$key] = [
                'revision' => $revision, 'expires_at' => $now + self::TTL_MS, 'model' => $model,
            ];
        }
        // 后续保存机器历史或插件读取关联时，不能修改供其他报告复用的对象。
        return $entry['model'] === null ? null : clone $entry['model'];
    }

    private static function revisionKey(string $modelClass): string
    {
        return 'realtime:metadata:revision:' . ($modelClass === Server::class ? 'nodes' : 'machines');
    }
}
