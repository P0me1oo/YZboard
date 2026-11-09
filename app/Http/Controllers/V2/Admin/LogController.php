<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagementLogQuery;
use App\Services\LogSettings;
use App\Services\RouteBlockLog;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function blocks(Request $request, \App\Services\RouteBlockLog $logs, StatisticsQueryLimiter $limiter)
    {
        return $this->success($limiter->run(fn () => $logs->records($request)))->header('Cache-Control', 'no-store, private');
    }

    public function blockSettings()
    {
        return $this->success(['retention_days' => RouteBlockLog::days()])->header('Cache-Control', 'no-store, private');
    }

    public function saveBlockSettings(Request $request)
    {
        $data = $request->validate([
            'retention_days' => 'required|integer|min:1|max:' . RouteBlockLog::MAX_DAYS,
            'enabled' => 'prohibited',
        ]);
        RouteBlockLog::saveDays((int) $data['retention_days']);
        return $this->blockSettings();
    }

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
