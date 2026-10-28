<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagementLogQuery;
use App\Services\LogSettings;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function settings()
    {
        return $this->success(LogSettings::view())->header('Cache-Control', 'no-store, private');
    }

    public function saveSettings(Request $request)
    {
        $modules = implode(',', array_keys(LogSettings::DEFAULT_DAYS));
        $rules = ['settings' => 'required|array:' . $modules . '|required_array_keys:' . $modules];
        foreach (LogSettings::DEFAULT_DAYS as $module => $days) {
            $rules['settings.' . $module] = 'required|array:enabled,retention_days|required_array_keys:enabled,retention_days';
            $rules['settings.' . $module . '.enabled'] = 'required|boolean';
            $rules['settings.' . $module . '.retention_days'] = 'required|integer|min:1|max:' . LogSettings::MAX_DAYS;
        }
        $data = $request->validate($rules);
        LogSettings::save($data['settings']);
        return $this->success(LogSettings::view())->header('Cache-Control', 'no-store, private');
    }

    public function nodes(ManagementLogQuery $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->agentNodes()))->header('Cache-Control', 'no-store, private');
    }

    public function fetch(Request $request, string $category, ManagementLogQuery $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->fetch($request, $category)))->header('Cache-Control', 'no-store, private');
    }
}
