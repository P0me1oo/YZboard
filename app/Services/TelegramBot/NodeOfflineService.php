<?php

namespace App\Services\TelegramBot;

use App\Models\Server;
use App\Models\TelegramBotConfig;
use Illuminate\Support\Facades\DB;

class NodeOfflineService
{
    public function schedule(): void
    {
        if (!TelegramBotConfig::find(1)?->notify_node_offline) return;
        Server::query()->chunkById(100, function ($nodes): void {
            foreach ($nodes as $node) {
                DB::transaction(function () use ($node): void {
                    $config = TelegramBotConfig::whereKey(1)->sharedLock()->first();
                    if (!$config?->notify_node_offline || !$node->enabled || $node->parent_id) return;
                    $state = DB::table('v2_telegram_node_states')->where('server_id', $node->id)->lockForUpdate()->first();
                    $heartbeat = (int) $node->last_check_at;
                    $lastSeen = max($heartbeat, (int) ($state->last_seen_at ?? 0));
                    // 从未收到心跳的节点不报掉线；保存已见心跳，避免缓存过期后丢失掉线状态。
                    if ($lastSeen <= 0 || $lastSeen > now()->timestamp) return;
                    if (!$state || $lastSeen > $state->last_seen_at) {
                        DB::table('v2_telegram_node_states')->updateOrInsert(['server_id' => $node->id], ['last_seen_at' => $lastSeen]);
                    }
                    if (!$config->enabled || !$config->bot_id
                        || now()->timestamp - $lastSeen <= $config->node_offline_minutes * 60) return;
                    DB::table('v2_telegram_node_incidents')->insertOrIgnore(['server_id' => $node->id, 'last_seen_at' => $lastSeen]);
                    $incidentId = DB::table('v2_telegram_node_incidents')->where('server_id', $node->id)
                        ->where('last_seen_at', $lastSeen)->value('id');
                    app(BusinessNotificationService::class)->recordNodeOffline((int) $incidentId);
                }, 3);
            }
        });
    }

    public function message(int $incidentId): ?string
    {
        $incident = DB::table('v2_telegram_node_incidents')->where('id', $incidentId)->first();
        $node = $incident ? Server::find($incident->server_id) : null;
        $config = TelegramBotConfig::find(1);
        if (!$node || !$node->enabled || $node->parent_id || !$config?->notify_node_offline) return null;
        $lastSeen = max((int) $node->last_check_at,
            (int) DB::table('v2_telegram_node_states')->where('server_id', $node->id)->value('last_seen_at'));
        // 排队或重试期间恢复、删除、停用、改成共享子节点后，不再发送旧告警。
        if ($lastSeen !== (int) $incident->last_seen_at
            || now()->timestamp - $lastSeen <= $config->node_offline_minutes * 60) return null;
        $clean = fn ($text) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $text) ?? ''), 0, 250);
        return implode("\n", [
            '节点掉线通知', '面板：' . $clean(admin_setting('app_name', 'YZboard')),
            '节点名称：' . $clean($node->name), '节点地址：' . $clean($node->host),
            '未收到心跳：' . (int) floor((now()->timestamp - $lastSeen) / 60) . ' 分钟',
        ]);
    }
}
