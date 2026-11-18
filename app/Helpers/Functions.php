<?php
use App\Support\Setting;

if (!function_exists('admin_setting')) {
    /**
     * 获取或保存配置参数.
     *
     * @param  string|array  $key
     * @param  mixed  $default
     * @return App\Support\Setting|mixed
     */
    function admin_setting($key = null, $default = null)
    {
        $setting = app(Setting::class);

        if ($key === null) {
            return $setting->toArray();
        }

        if (is_array($key)) {
            $setting->save($key);
            return '';
        }

        $default = config('v2board.' . $key) ?? $default;
        return $setting->get($key) ?? $default;
    }
}

if (!function_exists('subscribe_template')) {
    /**
     * Get subscribe template content by protocol name.
     */
    function subscribe_template(string $name): ?string
    {
        return \App\Models\SubscribeTemplate::getContent($name);
    }
}

if (!function_exists('admin_settings_batch')) {
    /**
     * 批量获取配置参数，性能优化版本
     *
     * @param array $keys 配置键名数组
     * @return array 返回键值对数组
     */
    function admin_settings_batch(array $keys): array
    {
        return app(Setting::class)->getBatch($keys);
    }
}

if (!function_exists('source_base_url')) {
    /**
     * 生成站点链接，优先使用管理端配置的站点地址。
     * @param string $path
     * @return string
     */
    function source_base_url(string $path = ''): string
    {
        $configured = admin_setting('app_url');
        $parsed = is_string($configured) ? parse_url($configured) : false;
        $baseUrl = $parsed && isset($parsed['host']) && in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            ? $configured : request()->getSchemeAndHttpHost();
        $baseUrl = rtrim($baseUrl, '/');
        $path = ltrim($path, '/');
        return $baseUrl . '/' . $path;
    }
}
