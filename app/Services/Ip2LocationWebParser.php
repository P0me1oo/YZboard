<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class Ip2LocationWebParser
{
    public function parse(string $html, string $ip): ?array
    {
        $document = new DOMDocument();
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return null;
        $nodes = (new DOMXPath($document))->query('//pre[@id="code2"]');
        if ($nodes->length !== 1) return null;
        try {
            $body = json_decode($nodes->item(0)->textContent, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        // 页面示例或错误地址不能写入当前 IP；只读取 HTML 内的 JSON，不执行脚本。
        if (!is_array($body) || !is_string($body['ip'] ?? null) || PublicDeviceIp::normalize($body['ip']) !== $ip) return null;
        $properties = IpProperties::fromResponse($body);
        $asn = null;
        $value = $body['asn'] ?? null;
        if ((is_string($value) || is_int($value)) && preg_match('/^(?:AS)?([0-9]{1,10})$/iD', (string) $value, $match)) {
            $number = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($number !== false) $asn = 'AS' . $number;
        }
        $name = $body['as'] ?? null;
        $name = is_string($name) && !in_array(trim($name), ['', '-'], true) ? mb_substr(trim($name), 0, 200) : null;
        $known = array_filter(array_diff_key($properties, ['proxy' => true]), static fn ($value) => $value !== null);
        if ($asn === null && $name === null && $known === [] && !in_array(true, array_map('is_bool', $properties['proxy']), true)) return null;
        return ['source' => 'ip2location.com', 'properties_version' => 2, 'asn' => $asn, 'as_name' => $name, 'ip_properties' => $properties];
    }
}
