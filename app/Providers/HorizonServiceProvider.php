<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Horizon\Console\WorkCommand;
use Symfony\Component\Console\Input\InputOption;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function register()
    {
        parent::register();

        // Laravel 12.71 读取此参数，Horizon 5.33.1 尚未声明；上游补齐后沿用其定义。
        $this->app->afterResolving(WorkCommand::class, static function (WorkCommand $command): void {
            if (!$command->getDefinition()->hasOption('stop-when-empty-for')) {
                $command->addOption('stop-when-empty-for', null, InputOption::VALUE_OPTIONAL,
                    'Stop when no jobs have been processed for the given number of seconds', '0');
            }
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        // Horizon::night();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewHorizon', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }
}
