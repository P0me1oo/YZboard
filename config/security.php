<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 浏览器安全响应头
    |--------------------------------------------------------------------------
    |
    | 这里的开关用于在部署环境出现兼容问题时快速关闭，而不需要改代码重新构建。
    | 全部通过环境变量控制，修改后需要执行 php artisan config:cache 重新生成缓存。
    |
    */

    'headers' => [

        // 总开关。关闭后所有安全响应头都不再下发。
        'enable' => (bool) env('SECURITY_HEADERS_ENABLE', true),

        // 是否下发内容安全策略（CSP）。
        'csp_enable' => (bool) env('SECURITY_CSP_ENABLE', true),

        // 仅上报模式：只发 Content-Security-Policy-Report-Only，不实际拦截。
        // 自定义主题或插件页面怀疑被 CSP 影响时，先开这个观察浏览器控制台。
        'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        // 允许哪些来源把本站嵌入 iframe。默认禁止任何嵌入，防点击劫持。
        'frame_ancestors' => env('SECURITY_FRAME_ANCESTORS', "'none'"),

        // HSTS 有效期（秒），仅在请求本身是 HTTPS 时下发。设为 0 关闭。
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        // HSTS 是否覆盖子域名。子域名还有纯 HTTP 服务时必须设为 false。
        'hsts_include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),

        // Referrer-Policy 取值。
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
    ],

];
