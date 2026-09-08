<?php

namespace App\Support;

use App\Utils\Helper;
use InvalidArgumentException;

/** 将节点保存的 Xray XHTTP 参数转换为 Mihomo 客户端选项。 */
final class MihomoXhttp
{
    private const FIELDS = [
        'path' => 'path',
        'host' => 'host',
        'mode' => 'mode',
        'headers' => 'headers',
        'noGRPCHeader' => 'no-grpc-header',
        'xPaddingBytes' => 'x-padding-bytes',
        'xPaddingObfsMode' => 'x-padding-obfs-mode',
        'xPaddingKey' => 'x-padding-key',
        'xPaddingHeader' => 'x-padding-header',
        'xPaddingPlacement' => 'x-padding-placement',
        'xPaddingMethod' => 'x-padding-method',
        'uplinkHTTPMethod' => 'uplink-http-method',
        'sessionPlacement' => 'session-placement',
        'sessionKey' => 'session-key',
        'sessionIDPlacement' => 'session-placement',
        'sessionIDKey' => 'session-key',
        'seqPlacement' => 'seq-placement',
        'seqKey' => 'seq-key',
        'uplinkDataPlacement' => 'uplink-data-placement',
        'uplinkDataKey' => 'uplink-data-key',
        'uplinkChunkSize' => 'uplink-chunk-size',
        'scMaxEachPostBytes' => 'sc-max-each-post-bytes',
        'scMinPostsIntervalMs' => 'sc-min-posts-interval-ms',
    ];

    private const REUSE_FIELDS = [
        'maxConcurrency' => 'max-concurrency',
        'maxConnections' => 'max-connections',
        'cMaxReuseTimes' => 'c-max-reuse-times',
        'hMaxRequestTimes' => 'h-max-request-times',
        'hMaxReusableSecs' => 'h-max-reusable-secs',
        'hKeepAlivePeriod' => 'h-keep-alive-period',
    ];

    private const RANGE_FIELDS = [
        'x-padding-bytes', 'uplink-chunk-size', 'sc-max-each-post-bytes', 'sc-min-posts-interval-ms',
        'max-concurrency', 'max-connections', 'c-max-reuse-times', 'h-max-request-times', 'h-max-reusable-secs',
    ];

    private const ZERO_DEFAULTS = [
        'x-padding-bytes' => '100-1000',
        'sc-max-each-post-bytes' => '1000000',
        'sc-min-posts-interval-ms' => '30',
    ];

    private const DEFAULT_REUSE = [
        'max-concurrency' => '0',
        'max-connections' => '6',
        'c-max-reuse-times' => '0',
        'h-max-request-times' => '600-900',
        'h-max-reusable-secs' => '1800-3000',
    ];

    private const PADDING_DEFAULTS = [
        'x-padding-key' => 'x_padding',
        'x-padding-header' => 'X-Padding',
        'x-padding-placement' => 'queryInHeader',
        'x-padding-method' => 'repeat-x',
    ];

    private const V124_FIELDS = [
        'x-padding-obfs-mode', 'x-padding-key', 'x-padding-header', 'x-padding-placement', 'x-padding-method',
        'uplink-http-method', 'session-placement', 'session-key', 'seq-placement', 'seq-key',
        'uplink-data-placement', 'uplink-data-key', 'uplink-chunk-size', 'sc-min-posts-interval-ms',
    ];

    public static function build(array $settings): array
    {
        $effective = self::effectiveSettings($settings);
        $options = self::transportOptions($effective);
        if (isset($effective['downloadSettings'])) {
            if (!is_array($effective['downloadSettings']) || ($options['mode'] ?? '') === 'stream-one') {
                throw new InvalidArgumentException('XHTTP 下载配置无效，或与 stream-one 模式冲突');
            }
            $options['download-settings'] = self::downloadOptions($effective['downloadSettings'], $options);
            // Mihomo 只有启用上行连接池后才会创建独立下载连接池。
            if (isset($options['download-settings']['reuse-settings']) && !isset($options['reuse-settings'])) {
                $options['reuse-settings'] = self::DEFAULT_REUSE;
            }
        }
        return $options;
    }

    public static function minimumVersion(array $options): string
    {
        $download = $options['download-settings'] ?? [];
        if (array_intersect(array_keys($options), self::V124_FIELDS)
            || isset($options['reuse-settings']['h-keep-alive-period'])
            || isset($download['reuse-settings']['h-keep-alive-period'])
            || in_array($download['alpn'] ?? [], [['h3'], ['http/1.1']], true)
            || (isset($options['sc-max-each-post-bytes']) && str_contains($options['sc-max-each-post-bytes'], '-'))) {
            return '1.19.24';
        }
        if (isset($options['reuse-settings']) || isset($download['reuse-settings']) || isset($options['sc-max-each-post-bytes'])) {
            return '1.19.23';
        }
        return '1.19.22';
    }

    private static function effectiveSettings(array $settings): array
    {
        if (!isset($settings['extra'])) {
            return $settings;
        }
        if (!is_array($settings['extra'])) {
            throw new InvalidArgumentException('XHTTP extra 必须是对象');
        }

        // Xray 使用 extra 替换高级设置，只有外层的 path、host、mode 始终保留。
        $basic = array_flip(['path', 'host', 'mode']);
        return array_intersect_key($settings, $basic) + array_diff_key($settings['extra'], $basic);
    }

    private static function transportOptions(array $settings): array
    {
        // 当前核对的 Mihomo 源码尚无这些字段，不能丢弃后继续下发。
        if (!empty($settings['sessionIDTable']) || !empty($settings['sessionIDLength'])) {
            throw new InvalidArgumentException('当前 Mihomo 转换不支持自定义 XHTTP 会话编号格式');
        }
        $options = self::mapFields($settings, self::FIELDS);
        if (!in_array($options['mode'] ?? '', ['', 'auto', 'stream-one', 'stream-up', 'packet-up'], true)) {
            throw new InvalidArgumentException('XHTTP 模式不受支持');
        }
        if ($options['x-padding-obfs-mode'] ?? false) {
            foreach (self::PADDING_DEFAULTS as $key => $default) {
                if (($options[$key] ?? '') === '') {
                    $options[$key] = $default;
                }
            }
        }
        $placement = $options['uplink-data-placement'] ?? '';
        if (in_array($placement, ['header', 'cookie'], true)) {
            if (($options['uplink-data-key'] ?? '') === '') {
                $options['uplink-data-key'] = $placement === 'header' ? 'X-Data' : 'x_data';
            }
            if (($options['uplink-chunk-size'] ?? '0') === '0') {
                $options['uplink-chunk-size'] = $placement === 'header' ? '3000-4000' : '2048-3072';
            }
        }
        if (isset($settings['xmux'])) {
            if (!is_array($settings['xmux'])) {
                throw new InvalidArgumentException('XHTTP xmux 必须是对象');
            }
            $options['reuse-settings'] = self::mapFields($settings['xmux'], self::REUSE_FIELDS);
            if (!array_filter($options['reuse-settings'], static fn($value) => (string) $value !== '0')) {
                $options['reuse-settings'] = array_replace($options['reuse-settings'], self::DEFAULT_REUSE);
            }
        }
        return $options;
    }

    private static function mapFields(array $settings, array $fields): array
    {
        $options = [];
        foreach ($fields as $source => $target) {
            if (!isset($settings[$source])) {
                continue;
            }
            $value = $settings[$source];
            if ($target === 'headers') {
                if (!is_array($value)) {
                    throw new InvalidArgumentException('XHTTP headers 必须是对象');
                }
                foreach ($value as $name => $header) {
                    if (!is_string($name) || !is_string($header) || strtolower($name) === 'host') {
                        throw new InvalidArgumentException('XHTTP 请求头无效，Host 应使用独立字段');
                    }
                }
                $value = $value ?: (object) [];
            } elseif (in_array($target, ['no-grpc-header', 'x-padding-obfs-mode'], true)) {
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    throw new InvalidArgumentException('XHTTP 开关必须是布尔值');
                }
                $value = (bool) $value;
            } elseif ($target === 'h-keep-alive-period') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new InvalidArgumentException('XHTTP 保活间隔必须是整数');
                }
                $value = (int) $value;
            } elseif (in_array($target, self::RANGE_FIELDS, true)) {
                $value = self::rangeValue($value);
                // Xray 的这些零值表示使用默认值，不能直接传给 Mihomo。
                if ($value === '0' && isset(self::ZERO_DEFAULTS[$target])) {
                    $value = self::ZERO_DEFAULTS[$target];
                }
            } elseif (!is_string($value)) {
                throw new InvalidArgumentException('XHTTP 文本选项必须是字符串');
            }
            $options[$target] = $target === 'uplink-http-method' ? strtoupper($value) : $value;
        }
        return $options;
    }

    private static function rangeValue($value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('XHTTP 范围必须是整数或范围字符串');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '0';
        }
        if (!preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Mihomo 不支持该 XHTTP 数值范围');
        }
        $left = (int) $matches[1];
        $right = (int) ($matches[2] ?? $matches[1]);
        if (max($left, $right) > 2147483647) {
            throw new InvalidArgumentException('XHTTP 数值范围超过 32 位整数上限');
        }
        // Xray 接受倒序范围并交换上下界，Mihomo 需要按升序传入。
        return $left === $right ? (string) $left : min($left, $right) . '-' . max($left, $right);
    }

    private static function downloadOptions(array $settings, array $upload): array
    {
        $network = $settings['network'] ?? 'xhttp';
        $security = $settings['security'] ?? 'none';
        if (!in_array($network, ['xhttp', 'splithttp'], true)
            || !in_array($security, ['', 'none', 'tls', 'reality'], true)
            || !is_string($settings['address'] ?? null)
            || trim($settings['address']) === ''
            || filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new InvalidArgumentException('XHTTP 下载链路的地址、端口或传输配置无效');
        }

        $transport = $settings['xhttpSettings'] ?? $settings['splithttpSettings'] ?? [];
        if (!is_array($transport)) {
            throw new InvalidArgumentException('XHTTP 下载传输配置必须是对象');
        }
        $effective = self::effectiveSettings($transport);
        if (isset($effective['downloadSettings'])) {
            throw new InvalidArgumentException('XHTTP 下载链路不能再次配置下载链路');
        }
        $http = self::transportOptions($effective);
        // 下载未填写的字段也使用 Xray 默认值，不能悄悄继承不同的上行会话或填充格式。
        $sharedDefaults = ['x-padding-bytes' => '100-1000', 'x-padding-obfs-mode' => false, 'session-placement' => 'path'];
        foreach ($sharedDefaults as $key => $default) {
            $upValue = $upload[$key] ?? $default;
            $downValue = $http[$key] ?? $default;
            if (($upValue === '' ? $default : $upValue) !== ($downValue === '' ? $default : $downValue)) {
                throw new InvalidArgumentException('Mihomo 不支持独立的下载会话或填充格式');
            }
        }
        $sessionPlacement = ($http['session-placement'] ?? '') ?: 'path';
        if ($sessionPlacement !== 'path') {
            $defaultKey = $sessionPlacement === 'header' ? 'X-Session' : 'x_session';
            if ((($http['session-key'] ?? '') ?: $defaultKey) !== (($upload['session-key'] ?? '') ?: $defaultKey)) {
                throw new InvalidArgumentException('Mihomo 不支持独立的下载会话字段名');
            }
        }
        foreach (array_diff_key($http, array_flip(['path', 'host', 'mode', 'headers', 'reuse-settings'])) as $key => $value) {
            if (!array_key_exists($key, $upload) || $upload[$key] !== $value) {
                throw new InvalidArgumentException('Mihomo 不支持在下载链路独立覆盖该 XHTTP 选项');
            }
        }

        $hasTls = in_array($security, ['tls', 'reality'], true);
        $tls = $hasTls ? ($settings[$security === 'reality' ? 'realitySettings' : 'tlsSettings'] ?? []) : [];
        if (!is_array($tls)) {
            throw new InvalidArgumentException('XHTTP 下载 TLS 配置必须是对象');
        }
        // Xray 的下载流配置独立于上行；显式清空可避免 Mihomo 继承上行的 TLS、ECH 和请求头。
        $options = [
            'server' => $settings['address'],
            'port' => (int) $settings['port'],
            'path' => $http['path'] ?? '/',
            'host' => $http['host'] ?? '',
            'headers' => $http['headers'] ?? (object) [],
            'tls' => $hasTls,
            'servername' => $tls['serverName'] ?? '',
            'alpn' => $hasTls ? ($security === 'tls' ? ($tls['alpn'] ?? ['h2']) : ['h2']) : ['http/1.1'],
            'skip-cert-verify' => $security === 'tls' && (bool) ($tls['allowInsecure'] ?? false),
            'client-fingerprint' => $tls['fingerprint'] ?? ($security === 'reality' ? 'chrome' : ''),
            'ech-opts' => ['enable' => false],
            'reality-opts' => ['public-key' => ''],
        ];
        if (isset($http['reuse-settings'])) {
            $options['reuse-settings'] = $http['reuse-settings'];
        } elseif (isset($upload['reuse-settings'])) {
            $options['reuse-settings'] = self::DEFAULT_REUSE;
        }
        if ($security === 'reality') {
            $options['reality-opts'] = [
                'public-key' => $tls['password'] ?? $tls['publicKey'] ?? '',
                'short-id' => $tls['shortId'] ?? '',
            ];
            if ($options['reality-opts']['public-key'] === '') {
                throw new InvalidArgumentException('XHTTP 下载 Reality 缺少客户端公钥');
            }
        } elseif ($security === 'tls') {
            $ech = $tls['ech'] ?? null;
            if (filled($tls['echConfigList'] ?? null)) {
                $value = $tls['echConfigList'];
                if (!is_string($value)) {
                    throw new InvalidArgumentException('XHTTP 下载 ECH 配置必须是字符串');
                }
                if (str_contains($value, '://')) {
                    $parts = explode('+', $value, 2);
                    $ech = ['enabled' => true, 'query_server_name' => count($parts) === 2 ? $parts[0] : null];
                } else {
                    $ech = ['enabled' => true, 'config' => $value];
                }
            }
            $options['ech-opts'] = Helper::toMihomoEchOptions($ech) ?? ['enable' => false];
        }

        return $options;
    }
}
