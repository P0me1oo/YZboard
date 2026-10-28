<?php

use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    'default' => env('LOG_CHANNEL', 'daily'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'backup' => [
            'driver' => 'single',
            'path' => storage_path('logs/backup.log'),
            'level' => 'debug',
        ],

        'single' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 0,
            'tap' => [App\Logging\ApplyLogSettings::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            // 到期文件由调度器按设置清理，避免按文件数量提前删除。
            'days' => 0,
            'tap' => [App\Logging\ApplyLogSettings::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'tap' => [App\Logging\ApplyLogSettings::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'tap' => [App\Logging\ApplyLogSettings::class],
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'tap' => [App\Logging\ApplyLogSettings::class],
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'deprecations' => [
            'driver' => 'daily',
            'path' => storage_path('logs/deprecations.log'),
            'level' => 'debug',
            'days' => 0,
            'tap' => [App\Logging\ApplyLogSettings::class],
        ],
    ],

];
