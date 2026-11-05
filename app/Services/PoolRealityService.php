<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

class PoolRealityService
{
    public const FIELDS = ['private_key', 'public_key', 'short_id', 'server_name', 'server_port'];

    public static function enabled(BalancerPool $pool): bool
    {
        return (bool) data_get($pool->template_config, 'reality_sync_enabled', false);
    }

    /** 池列表只返回可编辑的公开参数，私钥继续保存在加密模板中。 */
    public static function view(BalancerPool $pool): array
    {
        $settings = (array) data_get($pool->template_config, 'protocol_settings.reality_settings', []);
        return [...array_intersect_key($settings, array_flip(['public_key', 'short_id', 'server_name', 'server_port'])),
            'has_private_key' => !empty($settings['private_key'])];
    }

    public static function unchanged(BalancerPool $pool, array $input): bool
    {
        $settings = (array) data_get($pool->template_config, 'protocol_settings.reality_settings', []);
        foreach ($input as $key => $value) {
            if (in_array($key, ['private_key', 'public_key'], true) && trim((string) $value) === '') continue;
            if (in_array($key, ['short_id', 'server_name'], true)) $value = strtolower(trim((string) $value));
            if ($value != ($settings[$key] ?? ($key === 'server_port' ? 443 : ''))) return false;
        }
        return true;
    }

    /** 首次沿用首个节点；之后沿用池模板，重排或移除首节点不会轮换密钥。 */
    public static function prepare(BalancerPool $pool, Server $first, array $input): array
    {
        $settings = $pool->exists
            ? (array) data_get($pool->template_config, 'protocol_settings.reality_settings', [])
            : (array) data_get($first->protocol_settings, 'reality_settings', []);
        foreach (array_intersect_key($input, array_flip(self::FIELDS)) as $key => $value) {
            if (in_array($key, ['private_key', 'public_key'], true) && trim((string) $value) === '') continue;
            $settings[$key] = $value;
        }
        $private = trim((string) ($settings['private_key'] ?? ''));
        $decoded = preg_match('/\A[A-Za-z0-9_-]{43}\z/', $private)
            ? base64_decode(strtr($private, '-_', '+/') . '=', true) : false;
        if ($decoded === false || strlen($decoded) !== 32 || self::encode($decoded) !== $private) {
            self::invalid('private_key', 'Reality 私钥必须是有效的 32 字节 X25519 密钥');
        }
        $public = self::encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($decoded));
        // 更换私钥时公钥从新私钥派生；明确提交的公钥仍须匹配。
        $expected = !empty($input['private_key']) ? ($input['public_key'] ?? '') : ($settings['public_key'] ?? '');
        if (trim((string) $expected) !== '' && !hash_equals($public, trim((string) $expected))) {
            self::invalid('public_key', 'Reality 公钥与私钥不匹配，请使用同一组密钥');
        }
        $name = strtolower(rtrim(trim((string) ($settings['server_name'] ?? '')), '.'));
        if (!str_contains($name, '.') || strlen($name) > 253 || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($name, FILTER_VALIDATE_IP)) self::invalid('server_name', '请填写有效的 Reality 握手域名');
        $shortId = strtolower(trim((string) ($settings['short_id'] ?? '')));
        if (!preg_match('/\A(?:[0-9a-f]{2}){0,8}\z/', $shortId)) self::invalid('short_id', 'Reality 短 ID 应为空或最多 16 位的偶数长度十六进制字符串');
        $port = (string) ($settings['server_port'] ?? 443);
        if ($port === '') $port = '443';
        if (!preg_match('/\A[0-9]+\z/', $port) || (int) $port < 1 || (int) $port > 65535) {
            self::invalid('server_port', 'Reality 握手端口必须在 1–65535 之间');
        }
        return ['private_key' => $private, 'public_key' => $public, 'short_id' => $shortId, 'server_name' => $name, 'server_port' => (int) $port];
    }

    /** 只改池管理的参数，保留节点传输、独立回源、防盗用及其他设置。 */
    public static function apply(Server $node, array $settings): void
    {
        $protocol = (array) $node->protocol_settings;
        $reality = (array) ($protocol['reality_settings'] ?? []);
        $reality = array_replace($reality, $settings);
        if (!empty($reality['anti_abuse']) && trim((string) ($reality['dest'] ?? '')) !== '') {
            $host = parse_url('tcp://' . $reality['dest'], PHP_URL_HOST);
            if (strtolower(trim((string) $host, '[]')) !== $settings['server_name']) {
                self::invalid('server_name', $node->name . '：回源地址与统一握手域名不同，请先调整该节点回源地址或关闭防盗用模式');
            }
        }
        $protocol['reality_settings'] = $reality;
        $node->protocol_settings = $protocol;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['reality_settings.' . $field => $message]);
    }
}
