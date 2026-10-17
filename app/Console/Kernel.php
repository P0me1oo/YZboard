<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
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
        $schedule->command('xboard:statistics')->dailyAt('0:10')->onOneServer();
        // check
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
        $schedule->command('reset:log')->daily()->onOneServer()->withoutOverlapping(30);
        $schedule->call(fn () => app(\App\Services\SubscriptionRestrictionService::class)->prune())
            ->name('subscription-protection:prune')->hourly()->onOneServer()->withoutOverlapping(10);
        $schedule->command('inbound-ip:refresh')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        $schedule->command('access-log:prune')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground();
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
