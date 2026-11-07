<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /** 插件先登记命令，真正调用 Artisan 时再装配控制台应用。 */
    private array $pendingPluginCommands = [];

    public function registerPluginCommand(\Symfony\Component\Console\Command\Command $command): void
    {
        // 自定义注册钩子可能依赖控制台及容器，继续由原注册路径即时校验。
        if ($this->artisan !== null || !$command->getName() || !$this->canDeferPluginCommand($command)) {
            $this->registerCommand($command);
            return;
        }
        $command->getDefinition();
        $this->pendingPluginCommands[] = $command;
    }

    private function canDeferPluginCommand(\Symfony\Component\Console\Command\Command $command): bool
    {
        foreach (['getName', 'getAliases', 'getDefinition', 'isEnabled', 'setApplication', 'setHelperSet'] as $method) {
            if ((new \ReflectionMethod($command, $method))->getDeclaringClass()->getName()
                !== \Symfony\Component\Console\Command\Command::class) return false;
        }
        return !($command instanceof \Illuminate\Console\Command)
            || (new \ReflectionMethod($command, 'setLaravel'))->getDeclaringClass()->getName() === \Illuminate\Console\Command::class;
    }

    protected function getArtisan()
    {
        $artisan = parent::getArtisan();
        $commands = $this->pendingPluginCommands;
        $this->pendingPluginCommands = [];
        foreach ($commands as $index => $command) {
            try {
                $artisan->add($command);
            } catch (\Throwable $error) {
                // 失败命令不重复登记；尚未处理的命令保留给下一次控制台调用。
                $this->pendingPluginCommands = array_merge(array_slice($commands, $index + 1), $this->pendingPluginCommands);
                throw $error;
            }
        }
        return $artisan;
    }

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('node-health:prune')->hourly()->withoutOverlapping();
        $schedule->command('xboard:statistics')->dailyAt('0:10')->onOneServer();
        // check
        $schedule->command('ddns:sync')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('balancer:sync')->everyMinute()->onOneServer()->withoutOverlapping(2);
        $schedule->command('node:prune-traffic-grants')->everyMinute()->onOneServer()->withoutOverlapping(2);
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:commission')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:traffic-exceeded')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('node:sync-users')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('node:retry-reports')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        $schedule->command('node:prune-reports')->hourly()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('machine:prune-history')->hourlyAt(30)->onOneServer()->withoutOverlapping(10)->runInBackground();
        // reset
        $schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->command('reset:log')->daily()->onOneServer()->withoutOverlapping(30)->runInBackground();
        $schedule->command('reset:log --resume')->everyFiveMinutes()->onOneServer()->withoutOverlapping(30)->runInBackground();
        $schedule->call(fn () => app(\App\Services\SubscriptionRestrictionService::class)->prune())
            ->name('subscription-protection:prune')->hourly()->onOneServer()->withoutOverlapping(10);
        $schedule->command('inbound-ip:refresh')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        // 常规访问日志清理错开其他每五分钟任务；积压仅以小批预算续跑。
        $schedule->command('access-log:prune')->cron('1-59/5 * * * *')->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('access-log:prune --resume')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('management-log:prune')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('management-log:prune --resume')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->job(new \App\Jobs\FlushSubscriptionStatistics())->everyMinute()->onOneServer();
        $schedule->command('subscription-statistics:maintain')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('telegram-bot:prune-updates')->daily()->onOneServer()->withoutOverlapping(10);
        // send
        $schedule->command('telegram-bot:send-reminders')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer();
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // cleanup stale online_count (GC for Redis TTL expiration)
        $schedule->command('cleanup:online-status')->everyFiveMinutes()->onOneServer();
        // backup Timing
        // if (env('ENABLE_AUTO_BACKUP_AND_UPDATE', false)) {
        //     $schedule->command('backup:database', ['true'])->daily()->onOneServer();
        // }
        app(PluginManager::class)->registerPluginSchedules($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Exception $e) {
        }
        require base_path('routes/console.php');
    }
}
