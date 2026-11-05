<?php

namespace App\Support;

use InvalidArgumentException;

/** 按 Xray 的实际优先级统一保存、下发和订阅中的 XHTTP 参数。 */
final class XhttpSettings
{
    private const BASIC = ['path', 'host', 'mode'];
    private const CLIENT = ['headers', 'xPaddingBytes', 'xPaddingObfsMode', 'xPaddingKey',
        'xPaddingHeader', 'xPaddingPlacement', 'xPaddingMethod', 'uplinkHTTPMethod',
        'sessionIDPlacement', 'sessionIDKey', 'sessionIDTable', 'sessionIDLength',
        'seqPlacement', 'seqKey', 'uplinkDataPlacement', 'uplinkDataKey', 'uplinkChunkSize',
        'noGRPCHeader', 'scMaxEachPostBytes', 'scMinPostsIntervalMs', 'xmux', 'downloadSettings'];
    private const RANGES = ['xPaddingBytes', 'sessionIDLength', 'uplinkChunkSize',
        'scMaxEachPostBytes', 'scMinPostsIntervalMs', 'scStreamUpServerSecs'];
    private const OBJECTS = ['headers', 'xmux', 'downloadSettings', 'tlsSettings',
        'realitySettings', 'xhttpSettings', 'splithttpSettings', 'sockopt', 'h2Params'];

    public static function isXhttp(mixed $network): bool
    {
        return is_string($network) && in_array(strtolower(trim($network)), ['xhttp', 'splithttp'], true);
    }

    public static function normalize(array $settings): array
    {
        self::object($settings, 'network_settings');
        if (isset($settings['extra'])) {
            self::object($settings['extra'], 'extra');
            // extra 替换全部高级参数，外层三个基本字段始终优先。
            $settings = array_intersect_key($settings, array_flip(self::BASIC))
                + array_diff_key($settings['extra'], array_flip(self::BASIC));
        }
        unset($settings['extra']);
        foreach (['sessionPlacement' => 'sessionIDPlacement', 'sessionKey' => 'sessionIDKey'] as $old => $new) {
            if (!isset($settings[$new]) && isset($settings[$old])) $settings[$new] = $settings[$old];
            unset($settings[$old]);
        }
        if (isset($settings['xmux'])) {
            self::object($settings['xmux'], 'xmux');
            if (isset($settings['xmux']['cMaxLifetimeMs']) && !isset($settings['xmux']['hMaxReusableSecs'])) {
                [$min, $max] = self::range($settings['xmux']['cMaxLifetimeMs']);
                $min = (int) ceil($min / 1000); $max = (int) ceil($max / 1000);
                $settings['xmux']['hMaxReusableSecs'] = $min === $max ? $min : "$min-$max";
            }
            unset($settings['xmux']['cMaxLifetimeMs']);
        }
        if (isset($settings['downloadSettings'])) {
            self::object($settings['downloadSettings'], 'downloadSettings');
            $download = $settings['downloadSettings'];
            $download['network'] = $download['network'] ?? 'xhttp';
            if ((is_int($download['port'] ?? null) || is_string($download['port'] ?? null))
                && filter_var($download['port'], FILTER_VALIDATE_INT) !== false) $download['port'] = (int) $download['port'];
            if (isset($download['splithttpSettings']) && !isset($download['xhttpSettings'])) {
                $download['xhttpSettings'] = $download['splithttpSettings'];
            }
            unset($download['splithttpSettings']);
            if (($download['security'] ?? '') === 'reality') {
                self::object($download['realitySettings'] ?? [], 'downloadSettings.realitySettings');
                if (!isset($download['realitySettings']['fingerprint']) || $download['realitySettings']['fingerprint'] === '') {
                    $download['realitySettings']['fingerprint'] = 'chrome';
                }
            }
            if (isset($download['xhttpSettings'])) {
                self::object($download['xhttpSettings'], 'downloadSettings.xhttpSettings');
                // 下载不能继续嵌套，避免无界递归与不可用的客户端配置。
                $nested = $download['xhttpSettings'];
                if (isset($nested['downloadSettings']) || isset($nested['extra']['downloadSettings'])) {
                    throw new InvalidArgumentException('XHTTP 下载配置不能再次嵌套下载配置');
                }
                $download['xhttpSettings'] = self::normalize($nested);
            }
            $settings['downloadSettings'] = $download;
        }
        return $settings;
    }

    public static function client(array $settings): array
    {
        $settings = self::normalize($settings);
        $client = array_intersect_key($settings, array_flip([...self::BASIC, ...self::CLIENT]));
        if (isset($client['downloadSettings']['xhttpSettings'])) {
            $client['downloadSettings']['xhttpSettings'] = self::client($client['downloadSettings']['xhttpSettings']);
        }
        return self::stripSecrets($client);
    }

    public static function extraJson(array $settings): ?string
    {
        $extra = array_diff_key(self::client($settings), array_flip(self::BASIC));
        return $extra ? json_encode(self::wire($extra), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
    }

    /** 仅对象字段恢复为对象，ALPN 等有意义的空数组保持原类型。 */
    public static function wire(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (!is_array($value)) continue;
            $settings[$key] = $value === [] && in_array($key, self::OBJECTS, true)
                ? (object) [] : self::wire($value);
        }
        return $settings;
    }

    public static function validate(array $settings): void
    {
        $s = self::normalize($settings);
        $mode = $s['mode'] ?? 'auto';
        if (!in_array($mode, ['', 'auto', 'packet-up', 'stream-up', 'stream-one'], true)) {
            throw new InvalidArgumentException('XHTTP 模式无效');
        }
        foreach (self::BASIC as $field) {
            if (isset($s[$field]) && !is_string($s[$field])) throw new InvalidArgumentException("XHTTP $field 必须是字符串");
        }
        foreach (['xPaddingKey', 'xPaddingHeader', 'uplinkHTTPMethod', 'sessionIDKey', 'sessionIDTable', 'seqKey', 'uplinkDataKey'] as $field) {
            if (isset($s[$field]) && !is_string($s[$field])) throw new InvalidArgumentException("XHTTP $field 必须是字符串");
        }
        foreach (['headers', 'xmux', 'h2Params'] as $field) if (isset($s[$field])) self::object($s[$field], $field);
        foreach ($s['headers'] ?? [] as $key => $value) {
            if (!is_string($key) || !is_string($value) || strtolower($key) === 'host') {
                throw new InvalidArgumentException('XHTTP 请求头必须是字符串，Host 请使用独立字段');
            }
        }
        foreach (self::RANGES as $field) if (isset($s[$field])) self::range($s[$field]);
        if (isset($s['xPaddingBytes']) && self::range($s['xPaddingBytes']) !== [0, 0]
            && self::range($s['xPaddingBytes'])[0] <= 0) throw new InvalidArgumentException('XHTTP 填充字节数不能为零');
        foreach (['noGRPCHeader', 'noSSEHeader', 'xPaddingObfsMode'] as $field) {
            if (isset($s[$field]) && !is_bool($s[$field])) throw new InvalidArgumentException("XHTTP $field 必须是布尔值");
        }
        foreach (['scMaxBufferedPosts', 'serverMaxHeaderBytes'] as $field) {
            if (isset($s[$field]) && (!is_int($s[$field]) || $s[$field] < 0 || $s[$field] > 2147483647)) {
                throw new InvalidArgumentException("XHTTP $field 必须是非负整数");
            }
        }
        foreach (['sessionIDPlacement' => ['', 'path', 'header', 'cookie', 'query'],
            'seqPlacement' => ['', 'path', 'header', 'cookie', 'query'],
            'xPaddingPlacement' => ['', 'header', 'cookie', 'query', 'queryInHeader'],
            'xPaddingMethod' => ['', 'repeat-x', 'tokenish'],
            'uplinkDataPlacement' => ['', 'auto', 'body', 'header', 'cookie']] as $field => $values) {
            if (isset($s[$field]) && !in_array($s[$field], $values, true)) throw new InvalidArgumentException("XHTTP $field 无效");
        }
        if ((in_array($s['uplinkDataPlacement'] ?? '', ['header', 'cookie'], true)
            || strtoupper((string) ($s['uplinkHTTPMethod'] ?? '')) === 'GET') && $mode !== 'packet-up') {
            throw new InvalidArgumentException('XHTTP 通过请求头、Cookie 或 GET 上传时必须选择 packet-up');
        }
        foreach (['maxConcurrency', 'maxConnections', 'cMaxReuseTimes', 'hMaxRequestTimes', 'hMaxReusableSecs'] as $field) {
            if (isset($s['xmux'][$field])) self::range($s['xmux'][$field]);
        }
        if (self::range($s['xmux']['maxConcurrency'] ?? 0)[1] > 0
            && self::range($s['xmux']['maxConnections'] ?? 0)[1] > 0) {
            throw new InvalidArgumentException('XHTTP 连接池不能同时设置并发数和连接数');
        }
        if (isset($s['xmux']['hKeepAlivePeriod']) && !is_int($s['xmux']['hKeepAlivePeriod'])) {
            throw new InvalidArgumentException('XHTTP 保活时间必须是整数');
        }
        self::validateSession($s);
        if (isset($s['downloadSettings'])) {
            $d = $s['downloadSettings'];
            if ($mode === 'stream-one') throw new InvalidArgumentException('stream-one 不支持上下行分流');
            if (!is_string($d['address'] ?? null) || trim($d['address']) === ''
                || filter_var($d['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
                || !self::isXhttp($d['network'] ?? null)) throw new InvalidArgumentException('XHTTP 下载地址、端口或传输协议无效');
            if (!in_array($d['security'] ?? '', ['', 'none', 'tls', 'reality'], true)) throw new InvalidArgumentException('XHTTP 下载安全模式无效');
            foreach (['tlsSettings', 'realitySettings', 'sockopt'] as $field) if (isset($d[$field])) self::object($d[$field], "downloadSettings.$field");
            if (($d['security'] ?? '') === 'tls' && ($d['tlsSettings']['allowInsecure'] ?? false) === true) throw new InvalidArgumentException('当前 Xray 不支持 allowInsecure，请使用证书固定校验');
            if (($d['security'] ?? '') === 'reality' && empty($d['realitySettings']['publicKey']) && empty($d['realitySettings']['password'])) {
                throw new InvalidArgumentException('XHTTP 下载 Reality 缺少公钥');
            }
            self::validate($d['xhttpSettings'] ?? []);
        }
    }

    public static function range(mixed $value): array
    {
        if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('XHTTP 范围必须是整数或范围字符串');
        if ($value === '') return [0, 0];
        if (!preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/D', trim((string) $value), $m)) throw new InvalidArgumentException('XHTTP 数值范围无效');
        $a = (int) $m[1]; $b = (int) ($m[2] ?? $m[1]);
        if (max($a, $b) > 2147483647) throw new InvalidArgumentException('XHTTP 数值范围超过 32 位整数');
        return [min($a, $b), max($a, $b)];
    }

    private static function validateSession(array $s): void
    {
        if (empty($s['sessionIDTable'])) return;
        $table = $s['sessionIDTable'];
        if (!is_string($table) || preg_match('/[^\x00-\x7f]/', $table)) throw new InvalidArgumentException('XHTTP 会话字符表必须为 ASCII');
        $sizes = ['ALPHABET' => 26, 'Alphabet' => 52, 'alphabet' => 26, 'BASE36' => 36,
            'base36' => 36, 'Base62' => 62, 'HEX' => 16, 'hex' => 16, 'number' => 10];
        [$min, $max] = self::range($s['sessionIDLength'] ?? 0);
        if ($min < 1 || $max > 128) throw new InvalidArgumentException('XHTTP 会话长度应在 1 至 128 之间');
        $size = $sizes[$table] ?? strlen($table); $room = 0;
        for ($i = $min; $i <= $max && $room < 2147483648; $i++) $room += pow($size, $i);
        if ($room < 2147483648) throw new InvalidArgumentException('XHTTP 会话字符表或长度过小');
    }

    private static function object(mixed $value, string $field): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new InvalidArgumentException("XHTTP $field 必须是对象");
    }

    private static function stripSecrets(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower(str_replace(['_', '-'], '', (string) $key)),
                ['privatekey', 'decryption', 'certificates', 'echserverkeys', 'keycontent'], true)) continue;
            $out[$key] = is_array($item) ? self::stripSecrets($item) : $item;
        }
        return $out;
    }
}
