<?php

// 独立 DNS 验证不启动 Laravel，也不读取面板环境或数据库。
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\CarrierDns;

$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$domain = 'carrier.example.test';
$records = (new CarrierDns())->records($domain, $data, 'example.test');
$targets = ['default' => 'default.' . $domain . '.', 'telecom' => 'telecom.' . $domain . '.',
    'unicom' => 'unicom.' . $domain . '.', 'mobile' => 'mobile.' . $domain . '.'];
$records[] = CarrierDns::alias('user.' . $domain, $domain, $targets, true, 'example.test');
$records[] = CarrierDns::alias('fallback.' . $domain, $domain, ['default' => $targets['default']], true, 'example.test');
$records[] = CarrierDns::alias('expired.' . $domain, $domain, $targets, false);
$records[] = CarrierDns::alias('missing.' . $domain, 'missing.' . $domain, $targets, true, 'example.test');
$expiredDomain = 'old.' . $domain;
$records = array_merge($records, (new CarrierDns())->records($expiredDomain, [...$data, 'expires_at' => time() - 1], 'example.test'));
$records[] = CarrierDns::alias('stale.' . $domain, $expiredDomain, $targets, true, 'example.test');
$partialDomain = 'partial.' . $domain;
$partial = (new CarrierDns())->records($partialDomain, $data, 'example.test');
array_shift($partial);
$records = array_merge($records, $partial);
$records[] = CarrierDns::alias('partial.' . $domain, $partialDomain, $targets, true, 'example.test');
echo json_encode($records, JSON_THROW_ON_ERROR);
