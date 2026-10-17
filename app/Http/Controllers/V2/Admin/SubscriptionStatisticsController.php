<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionStatistics;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class SubscriptionStatisticsController extends Controller
{
    public function __construct(private readonly SubscriptionStatistics $statistics) {}

    public function users(Request $request)
    {
        $range = $this->statistics->range($request);
        return $this->success(app(StatisticsQueryLimiter::class)->run(fn () => $this->statistics->users($range)));
    }

    public function user(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        $range = $this->statistics->range($request);
        return $this->success(app(StatisticsQueryLimiter::class)->run(fn () => $this->statistics->user($range)));
    }

    public function events(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1', 'ip' => 'required|string|max:45', 'client' => 'required|string|max:32']);
        $range = $this->statistics->range($request);
        return $this->success(app(StatisticsQueryLimiter::class)->run(fn () => $this->statistics->events($range)));
    }

    public function days(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1', 'ip' => 'required|string|max:45', 'client' => 'required|string|max:32']);
        $range = $this->statistics->range($request);
        return $this->success(app(StatisticsQueryLimiter::class)->run(fn () => $this->statistics->days($range)));
    }
}
