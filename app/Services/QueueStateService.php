<?php

namespace App\Services;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

class QueueStateService
{
    public function snapshot(): array
    {
        $masters = collect(app(MasterSupervisorRepository::class)->all());
        $supervisors = collect(app(SupervisorRepository::class)->all());
        return [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $masters->where('status', 'paused')->count(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $supervisors->sum(fn ($supervisor) => collect($supervisor->processes)->sum()),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $masters->isNotEmpty() && !$masters->contains(fn ($master) => $master->status === 'paused'),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1)->all(),
        ];
    }
}
