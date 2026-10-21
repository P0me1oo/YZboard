<?php

namespace App\Services\TelegramBot;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\TelegramBotConfig;
use App\Services\LogSanitizer;
use Illuminate\Support\Facades\DB;

class XrayCrashService
{
    public function message(int $logId): ?string
    {
        $log = DB::table('v2_agent_log')->where('id', $logId)->first();
        $config = TelegramBotConfig::find(1);
        // 只接收明确的核心故障事件，普通连接错误和停止日志不触发告警。
        if (!$config?->notify_xray_crash || !$log || $log->event !== 'xray.crash'
            || $log->source !== 'xray' || !in_array($log->level, ['error', 'fatal'], true)
            || $log->occurred_at < now()->subHour()->timestamp) return null;
        $node = $log->node_id ? Server::find($log->node_id) : null;
        $machine = $log->machine_id ? ServerMachine::find($log->machine_id) : null;
        if (!$node || !$node->enabled || $node->parent_id
            || Server::effectiveKernelType($node->kernel_type) !== Server::KERNEL_XRAY
            || ($log->machine_id && (!$machine || !$machine->is_active || $node->machine_id !== $machine->id))) return null;
        $clean = fn ($value) => mb_substr(LogSanitizer::text((string) $value), 0, 250);
        $text = implode("\n", array_filter([
            'Xray 核心崩溃通知', '面板：' . $clean(admin_setting('app_name', 'YZboard')),
            $machine ? '服务器：' . $clean($machine->name) . '（编号 ' . $machine->id . '）' : null,
            '节点：' . $clean($node->name) . '（编号 ' . $node->id . '）',
            '发生时间：' . \Carbon\Carbon::createFromTimestamp($log->occurred_at, config('app.timezone'))->format('Y-m-d H:i:s'),
            '原因：' . mb_substr(LogSanitizer::text($log->message), 0, 1600),
        ], fn ($line) => $line !== null));
        return mb_convert_encoding(mb_strcut(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 0, 7800, 'UTF-16LE'), 'UTF-8', 'UTF-16LE');
    }
}
