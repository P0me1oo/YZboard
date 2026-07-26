<?php

namespace App\Jobs;

use App\Models\StatServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 累计中转逻辑节点的落地线路流量。
 *
 * 数据来自入口服务器上对应逻辑节点的独立 Shadowsocks 出站计数器，只写入节点维度的
 * 统计，不触碰用户流量、不套用倍率，因此不会与入口上的用户扣费重复。
 */
class RelayNodeTrafficJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function __construct(
        private readonly int $serverId,
        private readonly string $serverType,
        private readonly int $u,
        private readonly int $d,
        private readonly string $recordType = 'd',
    ) {
        $this->onQueue('stat');
    }

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function handle(): void
    {
        if ($this->u <= 0 && $this->d <= 0) {
            return;
        }

        $recordAt = $this->recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));

        try {
            $this->upsertStat($recordAt);
            DB::table('v2_server')
                ->where('id', $this->serverId)
                ->incrementEach(
                    ['u' => $this->u, 'd' => $this->d],
                    ['updated_at' => Carbon::now()]
                );
        } catch (\Throwable $e) {
            Log::error("RelayNodeTrafficJob failed for server {$this->serverId}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function upsertStat(int $recordAt): void
    {
        $keys = [
            'record_at' => $recordAt,
            'server_id' => $this->serverId,
            'server_type' => $this->serverType,
            'record_type' => $this->recordType,
        ];

        DB::transaction(function () use ($keys) {
            $existing = StatServer::where($keys)->lockForUpdate()->first();

            if ($existing) {
                $existing->update([
                    'u' => $existing->u + $this->u,
                    'd' => $existing->d + $this->d,
                    'updated_at' => time(),
                ]);
                return;
            }

            StatServer::create($keys + [
                'u' => $this->u,
                'd' => $this->d,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        }, 3);
    }
}
