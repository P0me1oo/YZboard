<?php

namespace App\Services;

class LogSanitizer
{
    /** 只清理终端控制字符并限制长度，保留日志正文，不替换或截掉诊断内容。 */
    public static function text(string $text): string
    {
        $text = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        return mb_substr(trim($text), 0, 4096);
    }

    /** 审计只保留定位操作范围所需的编号和开关，配置、正文和任意嵌套内容不入库。 */
    public static function audit(array $input): array
    {
        $result = [];
        foreach (['id', 'user_id', 'group_id', 'machine_id'] as $key) {
            if (isset($input[$key]) && filter_var($input[$key], FILTER_VALIDATE_INT) !== false) $result[$key] = (int) $input[$key];
        }
        foreach (['ids', 'user_ids', 'node_ids'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $result[$key] = array_values(array_map('intval', array_filter(array_slice($input[$key], 0, 1000),
                    fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)));
            }
        }
        foreach (['confirmed', 'enable', 'enabled', 'show', 'sell', 'renew', 'banned', 'force_update', 'apply_to_new_nodes', 'token_updated'] as $key) {
            if (array_key_exists($key, $input) && in_array($input[$key], [true, false, 0, 1, '0', '1'], true)) $result[$key] = (bool) $input[$key];
        }
        foreach (['scope' => ['selected', 'filtered', 'all'], 'group_action' => ['add', 'remove'], 'action' => ['upgrade', 'restart']] as $key => $allowed) {
            if (isset($input[$key]) && in_array($input[$key], $allowed, true)) $result[$key] = $input[$key];
        }
        return $result;
    }
}
