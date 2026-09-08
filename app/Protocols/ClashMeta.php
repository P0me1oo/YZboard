<?php

namespace App\Protocols;

use App\Models\Server;
use App\Utils\Helper;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use App\Support\AbstractProtocol;
use App\Support\MihomoXhttp;

class ClashMeta extends AbstractProtocol
{
    public $flags = ['meta', 'mihomo', 'verge', 'flclash', 'nekobox', 'clashmetaforandroid'];
    const CUSTOM_TEMPLATE_FILE = 'resources/rules/custom.clashmeta.yaml';
    const CUSTOM_CLASH_TEMPLATE_FILE = 'resources/rules/custom.clash.yaml';
    const DEFAULT_TEMPLATE_FILE = 'resources/rules/default.clash.yaml';
    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_TROJAN,
        Server::TYPE_VLESS,
        Server::TYPE_HYSTERIA,
        Server::TYPE_TUIC,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_HTTP,
        Server::TYPE_MIERU,
    ];

    protected $protocolRequirements = [
        '*.vless.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'http' => '0.0.0',
                'h2' => '0.0.0',
                'httpupgrade' => '0.0.0',
                'xhttp' => '0.0.0', // 内核版本单独判断，不能使用应用外壳版本。
            ],
            'strict' => true,
        ],
        '*.vmess.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'http' => '0.0.0',
                'h2' => '0.0.0',
                'httpupgrade' => '0.0.0',
            ],
            'strict' => true,
        ],
        '*.trojan.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'httpupgrade' => '0.0.0',
            ],
            'strict' => true,
        ],
        'nekobox.hysteria.protocol_settings.version' => [
            1 => '0.0.0',
            2 => '1.2.7',
        ],
        'clashmetaforandroid.hysteria.protocol_settings.version' => [
            2 => '2.9.0',
        ],
        'nekoray.hysteria.protocol_settings.version' => [
            2 => '3.24',
        ],
        'verge.hysteria.protocol_settings.version' => [
            2 => '1.3.8',
        ],
        'ClashX Meta.hysteria.protocol_settings.version' => [
            2 => '1.3.5',
        ],
        'flclash.hysteria.protocol_settings.version' => [
            2 => '0.8.0',
        ],
    ];

    protected function isCompatible($server)
    {
        if (!parent::isCompatible($server)) {
            return false;
        }

        $version = $this->mihomoVersion();
        $type = data_get($server, 'type');
        $settings = data_get($server, 'protocol_settings', []);
        $minimum = match ($type) {
            Server::TYPE_ANYTLS => '1.19.3',
            Server::TYPE_MIERU => filled(data_get($settings, 'traffic_pattern')) ? '1.19.21' : '1.19.0',
            Server::TYPE_VLESS => data_get($settings, 'encryption.enabled')
                && !in_array(data_get($settings, 'encryption.encryption'), [null, '', 'none'], true) ? '1.19.13' : null,
            default => null,
        };
        if ($version !== null && $minimum !== null && version_compare($version, $minimum, '<')) {
            return false;
        }
        if (in_array($type, [Server::TYPE_HTTP, Server::TYPE_SOCKS], true) && data_get($settings, 'tls')) {
            // 当前 Mihomo 的 HTTP/SOCKS 出站没有 ECH 选项，SOCKS 也不能单独指定 TLS 服务名。
            if (Helper::toMihomoEchOptions(data_get($settings, 'tls_settings.ech'))) {
                return false;
            }
            $serverName = data_get($settings, 'tls_settings.server_name');
            if ($type === Server::TYPE_SOCKS && filled($serverName)
                && strcasecmp(rtrim($serverName, '.'), rtrim(data_get($server, 'host', ''), '.')) !== 0) {
                return false;
            }
        }
        if ($type === Server::TYPE_SHADOWSOCKS) {
            $plugin = data_get($settings, 'plugin');
            if (filled($plugin) && !in_array($plugin, ['obfs', 'obfs-local', 'v2ray-plugin', 'gost-plugin', 'shadow-tls', 'restls', 'kcptun'], true)) {
                return false;
            }
            if ($plugin === 'restls') {
                $options = self::buildShadowsocks(data_get($server, 'password', ''), $server)['plugin-opts'];
                if (!filled($options['host'] ?? null) || !filled($options['password'] ?? null)
                    || !in_array($options['version-hint'] ?? '', ['tls12', 'tls13'], true)) {
                    return false;
                }
            }
        }
        if (data_get($settings, 'network') === 'grpc'
            && ($type === Server::TYPE_TROJAN || data_get($settings, 'tls'))
            && filled($authority = data_get($settings, 'network_settings.authority'))) {
            $serverName = match ($type) {
                Server::TYPE_VLESS => match ((int) data_get($settings, 'tls')) {
                    1 => data_get($settings, 'tls_settings.server_name'),
                    2 => data_get($settings, 'reality_settings.server_name'),
                    default => null,
                },
                Server::TYPE_TROJAN => (int) data_get($settings, 'tls', 1) === 2
                    ? data_get($settings, 'reality_settings.server_name') : data_get($settings, 'tls_settings.server_name'),
                default => data_get($settings, 'tls') ? data_get($settings, 'tls_settings.server_name') : null,
            };
            $host = data_get($server, 'host', '');
            $address = (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . data_get($server, 'port');
            // 未填写 TLS 服务名时，显式使用连接主机不会改变 TLS 校验目标。
            $authorities = filled($serverName) ? [$serverName]
                : ($type === Server::TYPE_TROJAN ? [$host] : [$host, $address]);
            if (!in_array(strtolower($authority), array_map('strtolower', $authorities), true)) {
                return false;
            }
        }
        $ech = match ($type) {
            Server::TYPE_VMESS => data_get($settings, 'tls') ? data_get($settings, 'tls_settings.ech') : null,
            Server::TYPE_VLESS => (int) data_get($settings, 'tls') === 1 ? data_get($settings, 'tls_settings.ech') : null,
            Server::TYPE_TROJAN => (int) data_get($settings, 'tls', 1) !== 2 ? data_get($settings, 'tls_settings.ech') : null,
            Server::TYPE_HYSTERIA, Server::TYPE_TUIC, Server::TYPE_ANYTLS => data_get($settings, 'tls.ech'),
            default => null,
        };
        if (!self::echCompatible(Helper::toMihomoEchOptions($ech), $version)) {
            return false;
        }

        if ($type === Server::TYPE_VLESS && data_get($settings, 'network') === 'xhttp') {
            try {
                $options = MihomoXhttp::build(data_get($settings, 'network_settings') ?? []);
            } catch (\InvalidArgumentException $e) {
                return false;
            }
            if ($version !== null && version_compare($version, MihomoXhttp::minimumVersion($options), '<')) {
                return false;
            }
            if (!self::echCompatible(data_get($options, 'download-settings.ech-opts'), $version)) {
                return false;
            }
        }

        return true;
    }

    /** 只接受明确标注的内核版本；应用版本和未知版本不参与内核能力过滤。 */
    private function mihomoVersion(): ?string
    {
        $number = '([0-9]+(?:\.[0-9]+){0,2}(?:[-+][0-9a-z.-]+)?)';
        if (filled($this->userAgent)) {
            return preg_match('/\b(?:clash[._-]?meta|mihomo|meta)[\/\s]+v?' . $number . '/i', $this->userAgent, $matches)
                ? $matches[1]
                : null;
        }

        return in_array($this->clientName, ['meta', 'mihomo'], true)
            && preg_match('/^v?' . $number . '$/i', $this->clientVersion ?? '', $matches)
                ? $matches[1]
                : null;
    }

    private static function echCompatible(?array $options, ?string $version): bool
    {
        if ($version === null || !data_get($options, 'enable')) {
            return true;
        }

        $minimum = empty($options['config']) && filled(data_get($options, 'query-server-name'))
            ? '1.19.20'
            : '1.19.9';
        return version_compare($version, $minimum, '>=');
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = admin_setting('app_name', 'XBoard');

        $template = subscribe_template('clashmeta');

        $config = Yaml::parse($template);
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if ($item['type'] === Server::TYPE_SHADOWSOCKS) {
                array_push($proxy, self::buildShadowsocks($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_VMESS) {
                array_push($proxy, self::buildVmess($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_TROJAN) {
                array_push($proxy, self::buildTrojan($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_VLESS) {
                array_push($proxy, self::buildVless($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_HYSTERIA) {
                array_push($proxy, self::buildHysteria($item['password'], $item, $user));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_TUIC) {
                array_push($proxy, self::buildTuic($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_ANYTLS) {
                array_push($proxy, self::buildAnyTLS($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_SOCKS) {
                array_push($proxy, self::buildSocks5($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_HTTP) {
                array_push($proxy, self::buildHttp($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_MIERU) {
                array_push($proxy, self::buildMieru($item['password'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        $config = $this->buildRules($config);

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP);
        $yaml = str_replace('$app_name', admin_setting('app_name', 'XBoard'), $yaml);
        return response($yaml)
            ->header('content-type', 'text/yaml')
            ->header('subscription-userinfo', $this->buildSubscriptionUserInfo())
            ->header('profile-update-interval', '24')
            ->header('content-disposition', 'attachment;filename*=UTF-8\'\'' . rawurlencode($appName));
    }

    /**
     * Build the rules for Clash.
     */
    public function buildRules($config)
    {
        // Force the current subscription domain to be a direct rule
        $subsDomain = request()->header('Host');
        if ($subsDomain) {
            array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        }
        // // Force the nodes ip to be a direct rule
        // collect($this->servers)->pluck('host')->map(function ($host) {
        //     $host = trim($host);
        //     return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : Helper::getIpByDomainName($host);
        // })->flatten()->unique()->each(function ($nodeIP) use (&$config) {
        //     array_unshift($config['rules'], "IP-CIDR,{$nodeIP}/32,DIRECT,no-resolve");
        // });

        return $config;
    }

    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = data_get($server['protocol_settings'], 'cipher');
        $array['password'] = data_get($server, 'password', $password);
        $array['udp'] = true;
        if (data_get($protocol_settings, 'plugin')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = (string) data_get($protocol_settings, 'plugin_opts', '');
            $array['plugin'] = $plugin;

            // 解析插件选项
            $parsedOpts = collect(explode(';', $pluginOpts))
                ->filter()
                ->mapWithKeys(function ($pair) {
                    if (!str_contains($pair, '=')) {
                        return [trim($pair) => true];
                    }
                    [$key, $value] = explode('=', $pair, 2);
                    return [trim($key) => trim($value)];
                })
                ->all();

            // 根据插件类型进行字段映射
            switch ($plugin) {
                case 'obfs':
                case 'obfs-local':
                    $array['plugin'] = 'obfs';
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['obfs'] ?? ($parsedOpts['mode'] ?? 'http'),
                        'host' => $parsedOpts['obfs-host'] ?? ($parsedOpts['host'] ?? 'www.bing.com'),
                    ]);
                    break;

                case 'v2ray-plugin':
                case 'gost-plugin':
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['mode'] ?? 'websocket',
                        'tls' => self::pluginBoolean($parsedOpts, 'tls', false),
                        'host' => $parsedOpts['host'] ?? null,
                        'path' => $parsedOpts['path'] ?? '/',
                        'mux' => self::pluginBoolean($parsedOpts, 'mux'),
                        'skip-cert-verify' => self::pluginBoolean($parsedOpts, 'skip-cert-verify'),
                        'fingerprint' => $parsedOpts['fingerprint'] ?? null,
                        'v2ray-http-upgrade' => $plugin === 'v2ray-plugin' ? self::pluginBoolean($parsedOpts, 'v2ray-http-upgrade') : null,
                        'v2ray-http-upgrade-fast-open' => $plugin === 'v2ray-plugin' ? self::pluginBoolean($parsedOpts, 'v2ray-http-upgrade-fast-open') : null,
                        'headers' => isset($parsedOpts['host']) ? ['Host' => $parsedOpts['host']] : null
                    ], fn($v) => $v !== null);
                    break;

                case 'shadow-tls':
                    $array['plugin-opts'] = array_filter([
                        'host' => $parsedOpts['host'] ?? null,
                        'password' => $parsedOpts['password'] ?? null,
                        'version' => isset($parsedOpts['version']) ? (int) $parsedOpts['version'] : 2,
                        'fingerprint' => $parsedOpts['fingerprint'] ?? null,
                        'skip-cert-verify' => self::pluginBoolean($parsedOpts, 'skip-cert-verify'),
                        'alpn' => isset($parsedOpts['alpn']) ? array_map('trim', explode(',', $parsedOpts['alpn'])) : null,
                    ], fn($v) => $v !== null);
                    break;

                case 'restls':
                    $array['plugin-opts'] = array_filter([
                        'host' => $parsedOpts['host'] ?? null,
                        'password' => $parsedOpts['password'] ?? null,
                        'version-hint' => $parsedOpts['version-hint'] ?? null,
                        'restls-script' => $parsedOpts['restls-script'] ?? null,
                    ], fn($v) => $v !== null);
                    break;

                default:
                    $array['plugin-opts'] = $parsedOpts;
            }
        }
        return $array;
    }

    private static function pluginBoolean(array $options, string $key, ?bool $default = null): ?bool
    {
        return array_key_exists($key, $options)
            ? filter_var($options[$key], FILTER_VALIDATE_BOOLEAN)
            : $default;
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vmess',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'alterId' => 0,
            'cipher' => 'auto',
            'udp' => true
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = (bool) data_get($protocol_settings, 'tls');
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            $array['servername'] = data_get($protocol_settings, 'tls_settings.server_name');
            self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
        }

        self::appendUtls($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings, $server);

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'none');
                $array['network'] = ($headerType === 'http') ? 'http' : 'tcp';
                if ($headerType === 'http') {
                    if (
                        $httpOpts = array_filter([
                            'method' => data_get($protocol_settings, 'network_settings.header.request.method'),
                            'headers' => data_get($protocol_settings, 'network_settings.header.request.headers'),
                            'path' => data_get($protocol_settings, 'network_settings.header.request.path', ['/'])
                        ])
                    ) {
                        $array['http-opts'] = $httpOpts;
                    }
                }
                break;
            case 'ws':
                self::appendWebsocket($array, $protocol_settings);
                break;
            case 'grpc':
                self::appendGrpc($array, $protocol_settings);
                break;
            case 'http': // 旧版 Xray 的 HTTP/2 传输别名。
            case 'h2':
                $array['network'] = 'h2';
                $array['h2-opts'] = [];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['h2-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['h2-opts']['host'] = is_array($host) ? $host : [$host];
                break;
            case 'httpupgrade':
                self::appendWebsocket($array, $protocol_settings, true);
                break;
            default:
                break;
        }

        return $array;
    }

    public static function buildVless($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vless',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $password,
            'udp' => true,
            'flow' => data_get($protocol_settings, 'flow'),
            'encryption' => match (data_get($protocol_settings, 'encryption.enabled')) {
                true => data_get($protocol_settings, 'encryption.encryption', 'none'),
                default => 'none'
            },
            'tls' => false
        ];

        switch (data_get($protocol_settings, 'tls')) {
            case 1:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['servername'] = $serverName;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                self::appendUtls($array, $protocol_settings);
                break;
            case 2:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                $array['servername'] = data_get($protocol_settings, 'reality_settings.server_name');
                $array['reality-opts'] = [
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id')
                ];
                self::appendUtls($array, $protocol_settings, true);
                break;
            default:
                break;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $array['network'] = 'tcp';
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'none');
                if ($headerType === 'http') {
                    $array['network'] = 'http';
                    if (
                        $httpOpts = array_filter([
                            'method' => data_get($protocol_settings, 'network_settings.header.request.method'),
                            'headers' => data_get($protocol_settings, 'network_settings.header.request.headers'),
                            'path' => data_get($protocol_settings, 'network_settings.header.request.path', ['/'])
                        ])
                    ) {
                        $array['http-opts'] = $httpOpts;
                    }
                }
                break;
            case 'ws':
                self::appendWebsocket($array, $protocol_settings);
                break;
            case 'grpc':
                self::appendGrpc($array, $protocol_settings);
                break;
            case 'http': // 旧版 Xray 的 HTTP/2 传输别名。
            case 'h2':
                $array['network'] = 'h2';
                $array['h2-opts'] = [];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['h2-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['h2-opts']['host'] = is_array($host) ? $host : [$host];
                break;
            case 'httpupgrade':
                self::appendWebsocket($array, $protocol_settings, true);
                break;
            case 'xhttp':
                $array['network'] = 'xhttp';
                $xhttpOpts = MihomoXhttp::build(data_get($protocol_settings, 'network_settings') ?? []);
                if ($xhttpOpts) {
                    $array['xhttp-opts'] = $xhttpOpts;
                }
                break;
            default:
                break;
        }

        self::appendMultiplex($array, $protocol_settings, $server);

        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'trojan',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
        ];

        $tlsMode = (int) data_get($protocol_settings, 'tls', 1);
        switch ($tlsMode) {
            case 2: // Reality
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'reality_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                $array['reality-opts'] = [
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id'),
                ];
                break;
            default: // Standard TLS
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                break;
        }

        self::appendUtls($array, $protocol_settings, $tlsMode === 2);
        self::appendMultiplex($array, $protocol_settings, $server);

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $array['network'] = 'tcp';
                break;
            case 'ws':
                self::appendWebsocket($array, $protocol_settings);
                break;
            case 'grpc':
                self::appendGrpc($array, $protocol_settings);
                break;
            case 'h2':
                $array['network'] = 'h2';
                $array['h2-opts'] = [];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['h2-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['h2-opts']['host'] = is_array($host) ? $host : [$host];
                break;
            case 'httpupgrade':
                self::appendWebsocket($array, $protocol_settings, true);
                break;
            default:
                $array['network'] = 'tcp';
                break;
        }

        return $array;
    }

    public static function buildHysteria($password, $server, $user)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'server' => $server['host'],
            'port' => $server['port'],
            'sni' => data_get($protocol_settings, 'tls.server_name'),
            'up' => data_get($protocol_settings, 'bandwidth.up'),
            'down' => data_get($protocol_settings, 'bandwidth.down'),
            'skip-cert-verify' => (bool) data_get($protocol_settings, 'tls.allow_insecure', false),
        ];
        if (isset($server['ports'])) {
            $array['ports'] = $server['ports'];
        }
        if ($hopInterval = data_get($protocol_settings, 'hop_interval')) {
            $array['hop-interval'] = (int) $hopInterval;
        }
        switch (data_get($protocol_settings, 'version')) {
            case 1:
                $array['type'] = 'hysteria';
                self::appendEch($array, data_get($protocol_settings, 'tls.ech'));
                if (Server::effectiveKernelType(data_get($server, 'kernel_type')) === Server::KERNEL_SINGBOX) {
                    // 配套 Node 的 sing-box HY1 入站显式使用 h3，与 Mihomo 的默认值不同。
                    $array['alpn'] = ['h3'];
                }
                $array['auth_str'] = $password;
                $array['protocol'] = 'udp'; // 支持 udp/wechat-video/faketcp
                if (data_get($protocol_settings, 'obfs.open')) {
                    $array['obfs'] = data_get($protocol_settings, 'obfs.password');
                }
                $array['fast-open'] = true;
                $array['disable_mtu_discovery'] = true;
                break;
            case 2:
                $array['type'] = 'hysteria2';
                $array['password'] = $password;
                self::appendEch($array, data_get($protocol_settings, 'tls.ech'));
                if (data_get($protocol_settings, 'obfs.open')) {
                    $array['obfs'] = data_get($protocol_settings, 'obfs.type');
                    $array['obfs-password'] = data_get($protocol_settings, 'obfs.password');
                }
                break;
        }

        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'udp' => true,
        ];

        if (data_get($protocol_settings, 'version') === 4) {
            $array['token'] = $password;
        } else {
            $array['uuid'] = $password;
            $array['password'] = $password;
        }

        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }

        if ($alpn = data_get($protocol_settings, 'alpn')) {
            $array['alpn'] = $alpn;
        }

        $array['congestion-controller'] = data_get($protocol_settings, 'congestion_control', 'cubic');
        $array['udp-relay-mode'] = data_get($protocol_settings, 'udp_relay_mode', 'native');
        self::appendEch($array, data_get($protocol_settings, 'tls.ech'));

        return $array;
    }

    public static function buildAnyTLS($password, $server)
    {

        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
        ];

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        if ($allowInsecure = data_get($protocol_settings, 'tls.allow_insecure')) {
            $array['skip-cert-verify'] = (bool) $allowInsecure;
        }
        self::appendEch($array, data_get($protocol_settings, 'tls.ech'));

        return $array;
    }

    public static function buildMieru($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'mieru',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
            'transport' => strtoupper(data_get($protocol_settings, 'transport', 'TCP')),
            'udp' => true,
        ];

        // 如果配置了端口范围
        if (filled($server['ports'] ?? null)) {
            unset($array['port']);
            $array['port-range'] = $server['ports'];
        }
        if ($pattern = data_get($protocol_settings, 'traffic_pattern')) {
            $array['traffic-pattern'] = $pattern;
        }

        return $array;
    }

    public static function buildSocks5($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'socks5';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['udp'] = true;

        $array['username'] = $password;
        $array['password'] = $password;

        // TLS 配置
        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
        }

        return $array;
    }

    public static function buildHttp($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'http';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];

        $array['username'] = $password;
        $array['password'] = $password;

        // TLS 配置
        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $array['sni'] = $serverName;
            }
        }

        return $array;
    }

    private function isMatch($exp, $str)
    {
        try {
            return preg_match($exp, $str) === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isRegex($exp)
    {
        if (empty($exp)) {
            return false;
        }
        try {
            return preg_match($exp, '') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function appendWebsocket(array &$array, array $settings, bool $upgrade = false): void
    {
        $array['network'] = 'ws';
        $options = $upgrade ? ['v2ray-http-upgrade' => true] : [];
        if ($path = data_get($settings, 'network_settings.path')) {
            $options['path'] = $path;
        }
        $headers = (array) data_get($settings, 'network_settings.headers', []);
        $host = data_get($settings, 'network_settings.host');
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'host') === 0) {
                $host = filled($host) ? $host : $value;
                unset($headers[$name]);
            }
        }
        // Xray 的客户端 Host 优先级为独立 host、旧 Host 请求头、TLS 服务名、连接地址。
        $host = filled($host) ? $host : ($array['servername'] ?? $array['sni'] ?? null);
        if (filled($host)) {
            $headers['Host'] = $host;
        }
        if ($headers) {
            $options['headers'] = $headers;
        }
        if ($options) {
            $array['ws-opts'] = $options;
        }
    }

    private static function appendGrpc(array &$array, array $settings): void
    {
        $array['network'] = 'grpc';
        if ($serviceName = data_get($settings, 'network_settings.serviceName')) {
            $array['grpc-opts']['grpc-service-name'] = $serviceName;
        }
        if ($userAgent = data_get($settings, 'network_settings.user_agent')) {
            $array['grpc-opts']['grpc-user-agent'] = $userAgent;
        }
        $authority = data_get($settings, 'network_settings.authority');
        if ($array['type'] !== 'trojan' && filled($authority)
            && (empty($array['tls'])
                || (empty($array['servername']) && strcasecmp($authority, $array['server']) === 0))) {
            // 无 TLS 时 servername 只控制 gRPC authority；启用 TLS 时不能改变校验目标。
            $array['servername'] = $authority;
        }
    }

    protected static function appendMultiplex(&$array, $protocol_settings, $server)
    {
        // 这里输出的是 sing-box 多路复用；Xray 服务端不使用此协议。
        if (Server::effectiveKernelType(data_get($server, 'kernel_type')) !== Server::KERNEL_SINGBOX) {
            return;
        }
        if ($multiplex = data_get($protocol_settings, 'multiplex')) {
            if (data_get($multiplex, 'enabled')) {
                $array['smux'] = array_filter([
                    'enabled' => true,
                    'protocol' => data_get($multiplex, 'protocol', 'yamux'),
                    'max-connections' => data_get($multiplex, 'max_connections'),
                    // 'min-streams' => data_get($multiplex, 'min_streams'),
                    // 'max-streams' => data_get($multiplex, 'max_streams'),
                    'padding' => data_get($multiplex, 'padding') ? true : null,
                ]);

                if (data_get($multiplex, 'brutal.enabled')) {
                    $array['smux']['brutal-opts'] = [
                        'enabled' => true,
                        'up' => data_get($multiplex, 'brutal.up_mbps'),
                        'down' => data_get($multiplex, 'brutal.down_mbps'),
                    ];
                }
            }
        }
    }

    protected static function appendUtls(&$array, $protocol_settings, bool $required = false)
    {
        if ($utls = data_get($protocol_settings, 'utls')) {
            if (data_get($utls, 'enabled')) {
                $array['client-fingerprint'] = Helper::getTlsFingerprint($utls);
            }
        }
        if ($required && (empty($array['client-fingerprint']) || $array['client-fingerprint'] === 'none')) {
            $array['client-fingerprint'] = 'chrome';
        }
    }

    protected static function appendEch(&$array, $ech): void
    {
        if ($options = Helper::toMihomoEchOptions($ech)) {
            $array['ech-opts'] = $options;
        }
    }
}
