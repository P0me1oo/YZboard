<?php

namespace App\Services;

class PublicDeviceIp
{
    private const EXCLUDED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const EXCLUDED_V6 = ['2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10'];

    public static function normalize(string $raw): ?string
    {
        $ip = trim($raw);
        if (preg_match('/^\[([^]]+)\]:\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        } elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        $binary = @inet_pton($ip);
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12);
        }
        $normalized = inet_ntop($binary);
        $excluded = strlen($binary) === 4 ? self::EXCLUDED_V4 : self::EXCLUDED_V6;
        if (strlen($binary) === 16 && !self::inRange($binary, '2000::/3')) {
            return null;
        }
        foreach ($excluded as $range) {
            if (self::inRange($binary, $range)) {
                return null;
            }
        }
        return $normalized;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$base, $bits] = explode('/', $range);
        $network = inet_pton($base);
        if (strlen($ip) !== strlen($network)) {
            return false;
        }
        $whole = intdiv((int) $bits, 8);
        $partial = (int) $bits % 8;
        return substr($ip, 0, $whole) === substr($network, 0, $whole)
            && ($partial === 0 || (ord($ip[$whole]) >> (8 - $partial)) === (ord($network[$whole]) >> (8 - $partial)));
    }
}
