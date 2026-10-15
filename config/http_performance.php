<?php

return [
    'enabled' => (bool) env('HTTP_PERFORMANCE_ENABLED', false),
    // 必须设置结束时间（Unix 秒）；到期自动停止采样。
    'until' => (int) env('HTTP_PERFORMANCE_UNTIL', 0),
    'sample_rate' => (float) env('HTTP_PERFORMANCE_SAMPLE_RATE', 0.1),
];
