<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\InboundIpStatistics;
use Illuminate\Http\Request;

class InboundIpStatisticsController extends Controller
{
    public function __construct(private readonly InboundIpStatistics $statistics) {}

    public function users(Request $request)
    {
        return $this->success($this->statistics->users($this->statistics->range($request)));
    }

    public function user(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        return $this->success($this->statistics->user($this->statistics->range($request)));
    }
}
