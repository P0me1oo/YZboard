<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseMaintenance;
use App\Services\StatisticsQueryLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DatabaseController extends Controller
{
    public function overview(DatabaseMaintenance $database, StatisticsQueryLimiter $limiter)
    {
        return $this->run($limiter, fn () => $database->overview());
    }

    public function preview(Request $request, DatabaseMaintenance $database, StatisticsQueryLimiter $limiter)
    {
        $values = $request->validate(['targets' => ['required', 'array', 'list', 'min:1', 'max:4'],
            'targets.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DatabaseMaintenance::TARGETS))]]);
        return $this->run($limiter, fn () => $database->preview($values['targets'], (int) Auth::guard('sanctum')->id()));
    }

    public function cleanup(Request $request, DatabaseMaintenance $database, StatisticsQueryLimiter $limiter)
    {
        $values = $request->validate(['token' => ['required', 'string', 'size:48', 'regex:/\A[a-zA-Z0-9]+\z/'],
            'confirmed' => ['required', 'accepted']]);
        return $this->run($limiter, fn () => $database->cleanup($values['token'], (int) Auth::guard('sanctum')->id()));
    }

    private function run(StatisticsQueryLimiter $limiter, callable $callback)
    {
        try {
            return $this->success($limiter->run($callback))->header('Cache-Control', 'no-store, private');
        } catch (QueryException) {
            // 不向浏览器返回 SQL、连接信息或数据库路径。
            abort(503, '数据库分析或清理失败，请稍后重试；清理失败的本轮操作已回滚。');
        }
    }
}
