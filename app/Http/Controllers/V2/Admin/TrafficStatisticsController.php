<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\TrafficStatisticsService;
use Illuminate\Http\Request;

class TrafficStatisticsController extends Controller
{
    public function __construct(private readonly TrafficStatisticsService $statistics) {}

    public function metadata(Request $request)
    {
        return $this->success($this->statistics->range($request)['meta']);
    }

    public function overview(Request $request)
    {
        return $this->success($this->statistics->overview($this->statistics->range($request)));
    }

    public function nodes(Request $request)
    {
        return $this->success($this->statistics->nodes($this->statistics->range($request)));
    }

    public function users(Request $request)
    {
        return $this->success($this->statistics->users($this->statistics->range($request)));
    }

    public function user(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        return $this->success($this->statistics->user($this->statistics->range($request)));
    }

    public function searchUsers(Request $request)
    {
        $input = $request->validate(['search' => 'required|string|max:100']);
        return $this->success($this->statistics->searchUsers($input['search']));
    }
}
