<?php

namespace App\Services\TelegramBot;

class SubscriptionLinkResolver
{
    public function token(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)
            || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new BindingException('invalid_link');
        }

        $bases = explode(',', (string) admin_setting('subscribe_url', ''));
        $bases[] = (string) admin_setting('app_url', config('app.url'));
        $subscribePath = '/' . trim((string) admin_setting('subscribe_path', 's'), '/');
        foreach (array_filter(array_map('trim', $bases)) as $baseUrl) {
            $base = parse_url($baseUrl);
            if (!$base || !$this->sameOrigin($parts, $base)) {
                continue;
            }
            $prefix = rtrim($base['path'] ?? '', '/');
            $path = rawurldecode($parts['path'] ?? '');
            if (!str_starts_with($path, $prefix . '/')) {
                continue;
            }
            $relative = substr($path, strlen($prefix));
            $query = [];
            parse_str($parts['query'] ?? '', $query);
            if ($relative === '/api/v1/client/subscribe') {
                $tokenKeys = array_filter(explode('&', $parts['query'] ?? ''), static function ($item) {
                    $key = urldecode(explode('=', $item, 2)[0]);
                    return $key === 'token' || str_starts_with($key, 'token[');
                });
                if (count($tokenKeys) === 1 && is_string($query['token'] ?? null)) {
                    return $this->validateToken($query['token']);
                }
            } elseif (!array_key_exists('token', $query) && str_starts_with($relative, $subscribePath . '/')) {
                return $this->validateToken(substr($relative, strlen($subscribePath) + 1));
            }
        }
        throw new BindingException('invalid_link');
    }

    private function validateToken(string $token): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $token)) {
            throw new BindingException('invalid_link');
        }
        return $token;
    }

    private function sameOrigin(array $url, array $base): bool
    {
        if (($url['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80))
                !== ($base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80))) {
            return false;
        }
        $host = strtolower($base['host'] ?? '');
        $chunks = preg_split('/(\[uuid\]|\[\d+-\d+\])/', $host, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $pattern = '';
        $ranges = [];
        foreach ($chunks as $chunk) {
            if ($chunk === '[uuid]') {
                $pattern .= '(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';
            } elseif (preg_match('/\A\[(\d+)-(\d+)\]\z/', $chunk, $range)) {
                $key = 'r' . count($ranges);
                $ranges[$key] = [min((int) $range[1], (int) $range[2]), max((int) $range[1], (int) $range[2])];
                $pattern .= '(?P<' . $key . '>\d{1,19})';
            } else {
                $pattern .= preg_quote($chunk, '~');
            }
        }
        if (!preg_match('~\A' . $pattern . '\z~', strtolower($url['host']), $matches)) {
            return false;
        }
        foreach ($ranges as $key => [$min, $max]) {
            $number = filter_var($matches[$key], FILTER_VALIDATE_INT);
            if ($number === false || $number < $min || $number > $max) {
                return false;
            }
        }
        return true;
    }
}
