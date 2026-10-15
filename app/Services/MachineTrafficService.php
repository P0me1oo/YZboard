<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** 服务器网卡计量独立于代理统计、用户扣费和管理员运行开关。 */
class MachineTrafficService
{
    public const GB = 1073741824;
    public const MAX_BYTES = 9007199254740991;
    public const LEASE_SECONDS = 30;

    public static function period(int $day, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $month = $now->startOfMonth();
        $boundary = fn ($month) => $month->day(min($day, $month->daysInMonth))->startOfDay();
        $start = $boundary($month);
        if ($now->lt($start)) {
            $start = $boundary($month->subMonth());
        }
        $end = $boundary($start->startOfMonth()->addMonth());
        return [$start->timestamp, $end->timestamp];
    }

    private static function state(ServerMachine $machine): array
    {
        [$start, $end] = self::period($machine->traffic_reset_day ?: 1);
        $state = $machine->traffic_state ?? [];
        $state += ['revision' => 1, 'epoch' => 1, 'start' => $start, 'end' => $end,
            'rx' => 0, 'tx' => 0, 'baseline' => 0, 'sequence' => 0,
            'initialized' => false, 'error' => null, 'reported_at' => 0, 'interfaces' => []];
        if (now()->timestamp >= $state['end']) {
            $state = array_replace($state, ['start' => $start, 'end' => $end,
                'rx' => 0, 'tx' => 0, 'baseline' => 0, 'sequence' => 0]);
        }
        return $state;
    }

    public static function used(ServerMachine $machine, array $state): int
    {
        return min(self::MAX_BYTES, $state['baseline'] + $state['tx']
            + ($machine->traffic_mode === 'both' ? $state['rx'] : 0));
    }

    public static function blocked(ServerMachine $machine): bool
    {
        if (!$machine->is_active) {
            return true;
        }
        if ($machine->traffic_limit <= 0) {
            return false;
        }
        $state = self::state($machine);
        return self::used($machine, $state) >= $machine->traffic_limit || !$state['initialized']
            || $state['error'] !== null || $state['reported_at'] <= now()->timestamp - self::LEASE_SECONDS;
    }

    public static function nodeBlocked(Server $node): bool
    {
        $machine = $node->machine_id ? ServerMachine::find($node->machine_id) : null;
        return $machine !== null && self::blocked($machine);
    }

    public static function view(ServerMachine $machine): array
    {
        $state = self::state($machine);
        return [
            'limit' => (int) $machine->traffic_limit, 'mode' => $machine->traffic_mode ?? 'outbound',
            'reset_day' => $machine->traffic_reset_day ?: 1, 'timezone' => config('app.timezone'),
            'interfaces' => $machine->traffic_interfaces ?? [], 'revision' => $state['revision'],
            'epoch' => $state['epoch'], 'period_start' => $state['start'], 'period_end' => $state['end'],
            'rx' => $state['rx'], 'tx' => $state['tx'], 'baseline' => $state['baseline'],
            'used' => self::used($machine, $state), 'sequence' => $state['sequence'],
            'initialized' => $state['initialized'], 'error' => $state['error'],
            'reported_at' => $state['reported_at'], 'measured_interfaces' => $state['interfaces'],
            'blocked' => self::blocked($machine), 'active' => (bool) $machine->is_active,
        ];
    }

    /** 在调用方持有的机器行锁内保存；首次开启从当前采样基线开始计量。 */
    public static function configure(ServerMachine $machine, array $params): void
    {
        $machine->traffic_mode ??= 'outbound';
        $machine->traffic_reset_day ??= 1;
        $machine->traffic_limit ??= 0;
        $state = self::state($machine);
        $previous = self::view($machine);
        if (array_key_exists('traffic_limit_gb', $params)) {
            $machine->traffic_limit = $params['traffic_limit_gb'] > 0
                ? max(1, (int) round($params['traffic_limit_gb'] * self::GB)) : 0;
        }
        foreach (['traffic_mode', 'traffic_reset_day', 'traffic_interfaces'] as $key) {
            if (array_key_exists($key, $params)) {
                $machine->$key = $params[$key];
            }
        }
        if ($previous['limit'] === 0 && $machine->traffic_limit > 0 && !($state['protection_started'] ?? false)) {
            $state = array_replace($state, ['epoch' => $state['epoch'] + 1,
                'baseline' => 0, 'protection_started' => true,
                'rx' => 0, 'tx' => 0, 'sequence' => 0, 'initialized' => false,
                'error' => null, 'reported_at' => 0]);
        }
        // 调整重置日只改变下一次归零日期，不在保存时清空当前用量。
        if ($previous['reset_day'] !== $machine->traffic_reset_day) {
            [, $state['end']] = self::period($machine->traffic_reset_day);
        }
        $state['revision']++;
        $machine->traffic_state = $state;
        // 保存配置会立即推送当前状态；首次计量解除等待时必须再推送恢复通知。
        $state['notified_blocked'] = self::blocked($machine);
        $machine->traffic_state = $state;
    }

    /** 单调累计值取较大者，重复、乱序和旧周期报告不重复计量。 */
    public static function exchange(int $id, ?array $report): array
    {
        if ($report !== null) {
            foreach (['epoch', 'period_start', 'sequence', 'rx', 'tx'] as $key) {
                $report[$key] = (int) $report[$key];
            }
            $report['initialized'] = (bool) $report['initialized'];
        }
        return DB::transaction(function () use ($id, $report) {
            $machine = ServerMachine::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $machine->traffic_state['notified_blocked'] ?? self::blocked($machine);
            $state = self::state($machine);
            if ($report !== null && $report['epoch'] === $state['epoch']
                && $report['period_start'] === $state['start'] && $report['sequence'] > $state['sequence']) {
                $state['rx'] = max($state['rx'], $report['rx']);
                $state['tx'] = max($state['tx'], $report['tx']);
                $state['sequence'] = $report['sequence'];
                $state['initialized'] = $report['initialized'];
                $state['error'] = $report['error'] ?? null;
                $state['interfaces'] = $report['interfaces'] ?? [];
                $state['reported_at'] = now()->timestamp;
            }
            $machine->traffic_state = $state;
            $after = self::blocked($machine);
            $state['notified_blocked'] = $after;
            $machine->traffic_state = $state;
            $machine->saveQuietly();
            if ($before !== $after) {
                DB::afterCommit(fn () => self::notify($id));
            }
            return self::view($machine);
        }, 3);
    }

    public static function notify(int $machineId): void
    {
        NodeRuntimeMetadata::invalidate(ServerMachine::class);
        NodeSyncService::notifyMachineNodesChanged($machineId);
        $entries = Server::where('machine_id', $machineId)->whereNotNull('relay_entry_id')
            ->pluck('relay_entry_id')->unique();
        foreach ($entries as $entryId) {
            NodeSyncService::notifyFullSync((int) $entryId);
        }
    }
}
