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
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.redis', ['driver' => 'array']);
        $app['cache']->forgetDriver('redis');
        $app->forgetInstance(\App\Support\Setting::class);

        return $app;
    }
}
