<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\TrafficReconciliation;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class TrafficReconciliationController extends Controller
{
    public function fetch(Request $r, TrafficReconciliation $service, StatisticsQueryLimiter $limiter)
    {
        return $limiter->run(fn () => $this->success($service->rows($r)));
    }
    public function detail(Request $r, TrafficReconciliation $service, StatisticsQueryLimiter $limiter)
    {
        return $limiter->run(fn () => $this->success($service->detail($r)));
    }
}
