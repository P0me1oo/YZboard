<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;

/**
 * 路由与节点的绑定关系。绑定只保存在节点的路由编号列表里，
 * 这里的增删只改动指定路由自己的编号，不影响节点上的其他路由。
 */
class ServerRouteService
{
    /**
     * 每条路由当前绑定的节点。
     *
     * @return array<int, int[]> 路由 ID => 节点 ID 列表
     */
    public static function nodeIdsByRoute(): array
    {
        $map = [];
        foreach (Server::query()->orderBy('id')->get(['id', 'route_ids']) as $server) {
            foreach (self::routeIdsOf($server) as $routeId) {
                $map[(int) $routeId][] = (int) $server->id;
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $map);
    }

    /**
     * 让指定路由恰好绑定到给定节点：新选中的追加本路由，取消选中的只移除本路由。
     * 调用方必须处于事务中；节点变更由节点观察者在提交后推送配置。
     *
     * @param int[] $nodeIds
     */
    public static function syncNodes(int $routeId, array $nodeIds): void
    {
        $wanted = array_fill_keys(array_map('intval', $nodeIds), true);
        $target = (string) $routeId;

        $servers = Server::query()->orderBy('id')->lockForUpdate()->get();
        foreach ($servers as $server) {
            $current = is_array($server->route_ids) ? $server->route_ids : [];
            $bound = in_array($target, self::routeIdsOf($server), true);
            $want = isset($wanted[(int) $server->id]);
            if ($want === $bound) {
                continue;
            }

            $server->route_ids = $want
                ? array_values([...$current, $target])
                : array_values(array_filter(
                    $current,
                    fn ($id) => self::canonicalRouteId($id) !== $target
                ));
            $server->save();
        }
    }

    /**
     * 新建节点默认选中的路由编号，与管理端提交的格式一致。
     *
     * @return string[]
     */
    public static function defaultRouteIdsForNewNode(): array
    {
        return ServerRoute::query()
            ->where('apply_to_new_nodes', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * 规范化目标端口：逗号分隔的单个端口或范围，统一为 "25,6881-6889"。
     * 返回 [规范值或 null, 错误信息或 null]。
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normalizePort(mixed $value): array
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return [null, null];
        }

        $parts = [];
        foreach (preg_split('/[,，\r\n]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^(\d{1,5})(?:\s*[-:]\s*(\d{1,5}))?$/', $token, $matches)) {
                return [null, "目标端口“{$token}”格式不正确"];
            }
            $start = (int) $matches[1];
            $end = isset($matches[2]) ? (int) $matches[2] : $start;
            if ($start < 1 || $end > 65535 || $start > $end) {
                return [null, "目标端口“{$token}”超出 1-65535 或范围颠倒"];
            }
            $normalized = $start === $end ? (string) $start : "{$start}-{$end}";
            if (!in_array($normalized, $parts, true)) {
                $parts[] = $normalized;
            }
        }

        return [$parts === [] ? null : implode(',', $parts), null];
    }

    /**
     * 节点当前绑定的路由编号，统一为不带前导零的数字字符串，忽略无法识别的值。
     *
     * @return string[]
     */
    private static function routeIdsOf(Server $server): array
    {
        $ids = [];
        foreach ((array) ($server->route_ids ?? []) as $id) {
            if (($canonical = self::canonicalRouteId($id)) !== null) {
                $ids[] = $canonical;
            }
        }

        return $ids;
    }

    private static function canonicalRouteId(mixed $id): ?string
    {
        if (!is_scalar($id)) {
            return null;
        }
        $id = trim((string) $id);

        return ctype_digit($id) ? (string) (int) $id : null;
    }
}
