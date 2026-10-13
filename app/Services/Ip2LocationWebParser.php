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
        $xpath = new DOMXPath($document);
        $fields = [];
        foreach ($xpath->query('//label') as $label) {
            $value = $xpath->query('./following-sibling::p[contains(concat(" ", normalize-space(@class), " "), " ip-result ")][1]', $label)->item(0);
            if ($value === null) continue;
            $name = $this->text($label->textContent);
            if (array_key_exists($name, $fields)) return null;
            $fields[$name] = $this->text($value->textContent);
        }
        if (PublicDeviceIp::normalize($fields['IP Address'] ?? '') !== $ip) return null;

        $body = ['isp' => $fields['ISP'] ?? null];
        if (preg_match('/^\(([A-Z]+(?:\/[A-Z]+)?)\)(?:\s|$)/D', $fields['Usage Type'] ?? '', $match)) {
            $body['usage_type'] = $match[1];
        }
        $body['is_proxy'] = match (strtolower($fields['Proxy'] ?? '')) {
            'yes' => true, 'no' => false, default => null,
        };
        // 网页明确列出的分类只证明对应的阳性，不用空值或其他分类推断“否”。
        $types = ['VPN' => 'is_vpn', 'TOR' => 'is_tor', 'DCH' => 'is_data_center', 'PUB' => 'is_public_proxy',
            'WEB' => 'is_web_proxy', 'SES' => 'is_web_crawler', 'AIC' => 'is_ai_crawler',
            'RES' => 'is_residential_proxy', 'CPN' => 'is_consumer_privacy_network', 'EPN' => 'is_enterprise_private_network'];
        $threats = ['SPAM' => 'is_spammer', 'SCANNER' => 'is_scanner', 'BOTNET' => 'is_botnet', 'BOGON' => 'is_bogon'];
        foreach (['Proxy Type' => $types, 'Threat' => $threats] as $label => $codes) {
            foreach (preg_split('/[\s,;\/]+/', $fields[$label] ?? '') as $code) {
                $field = $codes[trim($code, '()')] ?? null;
                if ($field !== null) $body['proxy'][$field] = true;
            }
        }
        $properties = IpProperties::fromResponse($body);
        $asn = null;
        if (preg_match('/^(?:AS)?([0-9]{1,10})$/iD', $fields['ASN'] ?? '', $match)) {
            $number = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($number !== false) $asn = 'AS' . $number;
        }
        $name = $fields['AS'] ?? null;
        $name = is_string($name) && !in_array($name, ['', '-'], true) ? mb_substr($name, 0, 200) : null;
        if ($asn === null && $name === null && $properties['isp'] === null && $properties['usage_type'] === null
            && $properties['is_proxy'] === null) return null;
        return ['asn' => $asn, 'as_name' => $name, 'ip_properties' => $properties];
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $value));
    }
}
