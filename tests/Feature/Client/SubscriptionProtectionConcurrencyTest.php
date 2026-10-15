<?php

namespace Tests\Feature\Client;

use App\Services\SubscriptionProtection;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SubscriptionProtectionConcurrencyTest extends TestCase
{
    public static function rules(): array
    {
        return [
            '请求限频' => [['rate_limit_enabled' => true, 'rate_limit_max_requests' => 5], 5],
            '失败封禁' => [['brute_force_enabled' => true, 'brute_force_max_failures' => 5], 4],
        ];
    }

    #[DataProvider('rules')]
    public function test_multiple_processes_share_limits_and_process_restart_preserves_them(array $rules, int $allowed): void
    {
        $root = PHP_OS_FAMILY === 'Windows' ? 'D:/codex-tmp' : sys_get_temp_dir();
        $directory = $root . '/yz-subscription-protection-' . bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0755, true);
        config()->set('cache.stores.protection_concurrency', ['driver' => 'file', 'path' => $directory]);
        $store = Cache::store('protection_concurrency');
        $store->put(Setting::CACHE_KEY, [SubscriptionProtection::SETTING => array_replace(SubscriptionProtection::defaults(), $rules)], 120);
        $workers = [];
        $makeWorker = fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/subscription-protection-worker.php'), $directory], base_path(), ['APP_ENV' => 'testing'], timeout: 60);
        try {
            for ($index = 0; $index < 8; $index++) {
                $worker = $makeWorker();
                $worker->start();
                $workers[] = $worker;
            }
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                array_push($statuses, ...array_filter(explode("\n", trim($worker->getOutput()))));
            }
            $this->assertCount(32, $statuses);
            $this->assertSame($allowed, count(array_filter($statuses, fn ($status) => $status === '403')));
            $this->assertSame(32 - $allowed, count(array_filter($statuses, fn ($status) => $status === '429')), json_encode(array_count_values($statuses)));
            $restart = $makeWorker();
            $restart->mustRun();
            $this->assertSame(['429', '429', '429', '429'], explode("\n", trim($restart->getOutput())));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) $worker->stop();
            }
            $resolved = str_replace('\\', '/', realpath($directory));
            $parent = str_replace('\\', '/', realpath($root));
            if (str_starts_with($resolved, $parent . '/yz-subscription-protection-')) {
                File::deleteDirectory($directory);
            }
        }
    }
}
