<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProxyAccessLog;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class AccessLogController extends Controller
{
    public function fetch(Request $request, ProxyAccessLog $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->records($request)))->header('Cache-Control', 'no-store, private');
    }
}
