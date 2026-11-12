<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** 只从经过校验的 CIDR 数据生成代码，管理表单不能输入 Lua。 */
class CarrierDns
{
    public const CARRIERS = ['telecom', 'unicom', 'mobile'];

    public function dataset(): ?array
    {
        $path = config('dns.carrier_ranges');
        if (!is_string($path) || !is_file($path) || !is_readable($path)) return null;
        // 文件可能在校验后被维护工具替换或移除，读取失败同样按缺测兜底。
        $raw = @file_get_contents($path, false, null, 0, 4 * 1024 * 1024 + 1);
        if (!is_string($raw) || strlen($raw) > 4 * 1024 * 1024) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp
            || !preg_match('/\A[a-f0-9]{40}\z/', $data['revision'] ?? '')) return null;
        foreach (self::CARRIERS as $carrier) {
            $ranges = $data['ranges'][$carrier] ?? null;
            if (!is_array($ranges) || !$ranges || count($ranges) > 30000) return null;
            foreach ($ranges as $cidr) {
                if (!is_string($cidr) || !preg_match('/\A([0-9a-fA-F:.]+)\/(\d{1,3})\z/', $cidr, $match)
                    || !filter_var($match[1], FILTER_VALIDATE_IP) || (int) $match[2] < 1
                    || (int) $match[2] > (str_contains($match[1], ':') ? 128 : 32)) return null;
            }
        }
        return $data;
    }

    public function status(): array
    {
        $data = $this->dataset();
        return ['ready' => (bool) config('dns.carrier_ready') && $data !== null,
            'reason' => !config('dns.carrier_ready') ? 'authorities_not_ready' : ($data ? null : 'dataset_unavailable'),
            'revision' => $data['revision'] ?? null, 'expires_at' => $data['expires_at'] ?? null];
    }

    public function assertReady(): void
    {
        $status = $this->status();
        if (!$status['ready']) throw ValidationException::withMessages(['scheduling_config.carrier_enabled' =>
            $status['reason'] === 'authorities_not_ready' ? '请先在全部权威 DNS 启用并验证 Lua 记录和 ECS，再设置 POWERDNS_CARRIER_READY'
                : '运营商网段库缺失、无效或已过期，请先更新网段库']);
    }

    public static function candidates(array $candidates, string $carrier): array
    {
        $matched = array_values(array_filter($candidates, fn ($c) => in_array($carrier, $c['carriers'], true)));
        if (!$matched && $carrier !== 'default') return self::candidates($candidates, 'default');
        // 默认线路也故障时，使用其他健康线路，不重新加入已剔除的成员。
        return $matched ?: $candidates;
    }

    public static function lua(string $type, string $code): string
    {
        // 4.9 会在 TXT 分段之间插入空格，5.x 默认直接拼接；只能在完整词法单元之间分段。
        preg_match_all("/'[^']*'|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|~=|>=|<=|==|./s", $code, $matches);
        $parts = []; $part = '';
        foreach ($matches[0] as $token) {
            if (strlen($token) > 255) throw new \InvalidArgumentException('Lua 字符串超出 DNS 分段上限');
            if (strlen($part . $token) > 255) { $parts[] = $part; $part = ''; }
            $part .= $token;
        }
        if ($part !== '') $parts[] = $part;
        return $type . ' ' . implode(' ', array_map(fn ($part) => '"' . addcslashes($part, "\\\"") . '"', $parts));
    }

    public function records(string $domain, ?array $data, ?string $zone = null): array
    {
        if (!$data) return [];
        $records = []; $includes = [];
        foreach (self::CARRIERS as $carrier) {
            $groups = [];
            foreach ($data['ranges'][$carrier] as $cidr) $groups[(int) explode('/', $cidr)[1]][] = $cidr;
            $index = 0;
            foreach ($groups as $bits => $ranges) foreach (array_chunk($ranges, 200) as $chunk) {
                $name = '_isp-' . $carrier . '-' . $index++ . '.' . $domain;
                $code = "if (not ecswho or ecswho:getBits()>=$bits) and netmask({" . implode(',', array_map(fn ($v) => "'$v'", $chunk))
                    . "}) then yz_carriers['$carrier']=true end;yz_parts=yz_parts+1";
                $records[] = ['name' => $name, 'type' => 'LUA', 'contents' => [self::lua('LUA', $code)]];
                $relative = substr($name, 0, -strlen($zone ?? $domain) - 1);
                $includes[] = "include('$relative')";
            }
        }
        $code = 'yz_carriers={};yz_parts=0;yz_ready=false;if os.time()<=' . $data['expires_at'] . ' then '
            . implode(';', $includes) . ';yz_ready=(yz_parts==' . count($includes) . ') end';
        $records[] = ['name' => '_isp.' . $domain, 'type' => 'LUA', 'contents' => [self::lua('LUA', $code)]];
        return $records;
    }

    public static function alias(string $name, string $domain, array $targets, bool $classify, ?string $zone = null): array
    {
        if (!$classify) return ['name' => $name, 'type' => 'CNAME', 'contents' => [$targets['default']]];
        $relative = substr('_isp.' . $domain, 0, -strlen($zone ?? $domain) - 1);
        $code = ";yz_carriers={};yz_ready=false;include('$relative');local n=0;local c='default';for k,v in pairs(yz_carriers) do if v then n=n+1;c=k end end;"
            . "if not yz_ready or n~=1 then c='default' end;local t={";
        foreach ($targets as $carrier => $target) $code .= "['$carrier']='$target',";
        $code .= "};return t[c] or t['default']";
        return ['name' => $name, 'type' => 'LUA', 'contents' => [self::lua('CNAME', $code)]];
    }
}
