<?php

namespace Tests\Concerns;

trait IsolatesLogStorage
{
    private string $logTestStorage;
    private string $previousLogStorage;

    private function isolateLogStorage(): void
    {
        $this->previousLogStorage = app()->storagePath();
        $this->logTestStorage = sys_get_temp_dir() . '/yz-log-settings-' . bin2hex(random_bytes(8));
        mkdir($this->logTestStorage . '/logs', 0700, true);
        app()->useStoragePath($this->logTestStorage);
        foreach (['daily', 'single', 'deprecations'] as $channel) {
            app('log')->forgetChannel($channel);
            config(['logging.channels.' . $channel . '.path' => storage_path('logs/' . ($channel === 'deprecations' ? 'deprecations' : 'laravel') . '.log')]);
        }
    }

    private function removeLogStorage(): void
    {
        foreach (app('log')->getChannels() as $logger) {
            $inner = $logger->getLogger();
            if (method_exists($inner, 'close')) $inner->close();
        }
        app()->useStoragePath($this->previousLogStorage);
        app('files')->deleteDirectory($this->logTestStorage);
    }
}
