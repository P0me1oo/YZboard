<?php

namespace App\Services;

final class NodeHealthProjection
{
    /** Agent 的总状态包含所有中转；节点自身只使用监听、目标及默认出站。 */
    public static function local(array $health): array
    {
        $routes = array_values(array_filter($health['routes'] ?? [], fn ($route) =>
            empty($route['node_id']) && ($route['tag'] ?? '') === ''));
        $steps = array_values(array_filter($health['steps'] ?? [], fn ($step) => ($step['name'] ?? '') !== 'routes'));
        if ($routes === ($health['routes'] ?? []) && $steps === ($health['steps'] ?? [])) {
            return $health;
        }

        $health['routes'] = $routes;
        $health['steps'] = $steps;
        $statuses = array_column(array_merge($steps, $routes), 'status');
        $health['status'] = match (true) {
            in_array('failed', $statuses, true) => 'failed',
            ($health['status'] ?? '') === 'unsupported' => 'unsupported',
            !$routes || in_array('incomplete', $statuses, true) => 'partial',
            default => 'ok',
        };
        return $health;
    }
}
