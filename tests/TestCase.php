<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(\App\Support\Setting::class);
    }

    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['db']->purge('sqlite');
        if (defined('YZ_TEST_MARIADB') && $app['config']->get('database.default') !== 'mysql') {
            throw new \RuntimeException('MariaDB 回归不能回退到其他数据库，请检查配置缓存');
        }
        if ($app['config']->get('database.default') === 'mysql') {
            $connection = $app['db']->connection();
            if (!$app->environment('testing') || $connection->getDatabaseName() !== 'yzboard_mariadb_test'
                || $connection->getConfig('host') !== '127.0.0.1') {
                throw new \RuntimeException('数据库回归仅允许使用专用 MariaDB 测试库');
            }
            $server = $connection->selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS mode');
            if (!str_contains($server->version, 'MariaDB')
                || !in_array('ONLY_FULL_GROUP_BY', explode(',', $server->mode), true)) {
                throw new \RuntimeException('MariaDB 回归必须开启严格分组检查');
            }
        }
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.redis', ['driver' => 'array']);
        $app['cache']->forgetDriver('redis');
        $app->forgetInstance(\App\Support\Setting::class);

        return $app;
    }

    /** 在事务内注入一次查询异常，避免建触发器在 MariaDB 中隐式提交事务。 */
    protected function failNextQueryMatching(string $pattern): void
    {
        $pending = true;
        $this->app['db']->connection()->beforeExecuting(function ($sql, $bindings, $connection) use ($pattern, &$pending): void {
            if ($pending && preg_match($pattern, $sql)) {
                $pending = false;
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings,
                    new \RuntimeException('测试数据库写入失败'));
            }
        });
    }
}
