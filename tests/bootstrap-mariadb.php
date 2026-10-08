<?php

// 仅连接本机专用测试库，避免 PHPUnit 的重建数据库操作读取部署环境配置。
require __DIR__ . '/../vendor/autoload.php';

$port = getenv('YZ_TEST_MARIADB_PORT') ?: '3306';
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    throw new RuntimeException('MariaDB 测试端口无效');
}

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => $port,
    'DB_DATABASE' => 'yzboard_mariadb_test',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => '',
    'DB_SOCKET' => '',
    'DATABASE_URL' => '',
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

$database = new PDO("mysql:host=127.0.0.1;port=$port;dbname=yzboard_mariadb_test", 'root', '');
if (!str_contains($database->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
    throw new RuntimeException('本项测试必须使用真实 MariaDB');
}
unset($database);
define('YZ_TEST_MARIADB', true);
