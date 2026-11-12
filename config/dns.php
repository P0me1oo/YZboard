<?php

return [
    // 四台权威完成 Lua/ECS 与区域传送验证后，由部署环境启用。
    'carrier_ready' => (bool) env('POWERDNS_CARRIER_READY', false),
    'carrier_ranges' => env('POWERDNS_CARRIER_RANGES', resource_path('dns/carriers.json')),
];
