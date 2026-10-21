<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagementLogQuery;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function nodes(ManagementLogQuery $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->agentNodes()))->header('Cache-Control', 'no-store, private');
    }

    public function fetch(Request $request, string $category, ManagementLogQuery $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->fetch($request, $category)))->header('Cache-Control', 'no-store, private');
    }
}
