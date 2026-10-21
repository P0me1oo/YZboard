<?php

namespace App\Services;

class LogSanitizer
{
    /** 日志只展示诊断文本；认证内容、完整地址和配置不进入新日志存储。 */
    public static function text(string $text): string
    {
        $text = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $text = preg_replace('/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----[\s\S]*?(?:-----END [^-]+-----|$)/i', '[证书或密钥已隐藏]', $text);
        $text = preg_replace('~(?:https?|wss?|ss|vmess|vless|trojan|hysteria2?|tuic)://[^\s"\'<>]+~i', '[地址已隐藏]', $text);
        // 认证字段包含空格和逗号，必须隐藏完整头部，不能只遮住认证方式。
        $text = preg_replace('/((?:proxy-)?authorization["\']?\s*[:=]\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\r\n}]+)/i', '$1[已隐藏]', $text);
        $text = preg_replace('/\b(?:Basic|Digest)\s+[^\r\n}]+/i', '[认证已隐藏]', $text);
        $text = preg_replace('/\bBearer\s+[^\s,"\'}]+/i', 'Bearer [已隐藏]', $text);
        $text = preg_replace('/\b[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b/i', '[身份已隐藏]', $text);
        $text = preg_replace('/\b[A-Za-z0-9+\/_=-]{32,}/', '[长标识已隐藏]', $text);
        $text = preg_replace('/(["\']?(?:[a-z_]*(?:password|passwd|token|secret|cookie|authorization|private_key|api_key|uuid|auth_data)[a-z_]*)["\']?\s*[=:]\s*)(?:"[^"\n]*"|\'[^\'\n]*\'|[^\s,;}]+)/i', '$1[已隐藏]', $text);
        // 框架异常可能附带 SQL 参数、请求正文或完整配置，保留错误本身即可。
        $text = preg_replace('/\s+(?:\{\s*"|\[stacktrace\]|\(Connection:)[\s\S]*$/', '', $text);
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
