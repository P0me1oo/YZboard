<?php

namespace App\Services;

/** 按固定格式读取纯真库；重定向和字符串读取均限制在文件范围内。 */
class QqwryLocation
{
    private $file = null;
    private int $size = 0;
    private int $first = 0;
    private int $last = 0;

    public function __construct(private readonly ?string $path = null) {}

    public function lookup(string $ip): array
    {
        $unknown = ['region' => '未知', 'province' => '未知'];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $unknown;
        try {
            $this->open();
            $number = unpack('N', inet_pton($ip))[1];
            $low = 0;
            $high = intdiv($this->last - $this->first, 7);
            $match = null;
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                $index = $this->read($this->first + $middle * 7, 7);
                if (unpack('V', substr($index, 0, 4))[1] <= $number) {
                    $match = $this->offset(substr($index, 4));
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }
            if ($match === null || $number > unpack('V', $this->read($match, 4))[1]) return $unknown;
            [$country, $area] = $this->record($match + 4);
            $place = $this->decode($country);
            $network = $this->decode($area);
            return [
                'region' => mb_substr(implode(' ', array_unique(array_filter([$place, $network]))), 0, 512) ?: '未知',
                'province' => mb_substr(IpProvince::fromChinese($place), 0, 128),
            ];
        } catch (\Throwable) {
            return $unknown;
        }
    }

    private function open(): void
    {
        if (is_resource($this->file)) return;
        $this->file = @fopen($this->path ?? resource_path('ip/qqwry.dat'), 'rb');
        if (!$this->file) throw new \RuntimeException('纯真数据库无法读取');
        $this->size = fstat($this->file)['size'];
        $header = unpack('Vfirst/Vlast', $this->read(0, 8));
        $this->first = $header['first'];
        $this->last = $header['last'];
        if ($this->first < 8 || $this->last < $this->first || $this->last + 7 > $this->size
            || ($this->last - $this->first) % 7 !== 0) {
            fclose($this->file);
            $this->file = null;
            throw new \UnexpectedValueException('纯真数据库索引无效');
        }
    }

    private function read(int $at, int $length): string
    {
        if ($at < 0 || $at + $length > $this->size || fseek($this->file, $at) !== 0) {
            throw new \UnexpectedValueException('纯真数据库偏移无效');
        }
        $bytes = fread($this->file, $length);
        if (strlen($bytes) !== $length) throw new \UnexpectedValueException('纯真数据库不完整');
        return $bytes;
    }

    private function offset(string $bytes): int { return unpack('V', $bytes . "\0")[1]; }

    private function record(int $at, int $depth = 0): array
    {
        if ($depth > 8) throw new \UnexpectedValueException('纯真数据库重定向循环');
        $flag = ord($this->read($at, 1));
        if ($flag === 1) return $this->record($this->offset($this->read($at + 1, 3)), $depth + 1);
        if ($flag === 2) {
            return [$this->string($this->offset($this->read($at + 1, 3))), $this->string($at + 4)];
        }
        $end = 0;
        $country = $this->string($at, 0, $end);
        return [$country, $this->string($end)];
    }

    private function string(int $at, int $depth = 0, ?int &$end = null): string
    {
        if ($at === 0) return '';
        if ($depth > 8) throw new \UnexpectedValueException('纯真数据库字符串重定向循环');
        $flag = ord($this->read($at, 1));
        if ($flag === 1 || $flag === 2) {
            $end = $at + 4;
            return $this->string($this->offset($this->read($at + 1, 3)), $depth + 1);
        }
        $bytes = $this->read($at, min(4096, $this->size - $at));
        $length = strpos($bytes, "\0");
        if ($length === false) throw new \UnexpectedValueException('纯真数据库字符串未结束');
        $end = $at + $length + 1;
        return substr($bytes, 0, $length);
    }

    private function decode(string $value): string
    {
        $value = trim(mb_convert_encoding($value, 'UTF-8', 'GB18030'));
        return in_array(strtoupper($value), ['CZ88.NET', 'IANA', '']) ? '' : $value;
    }

    public function __destruct() { if (is_resource($this->file)) fclose($this->file); }
}
