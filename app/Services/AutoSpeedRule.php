<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AutoSpeedRule
{
    public static function normalize(mixed $rule, ?int $base = null): ?array
    {
        if ($rule === null) {
            return null;
        }
        $v = Validator::make(['rule' => $rule], [
         'rule' => 'array:enabled,mode,direction,threshold_mbps,limit_mbps,duration_seconds,window_seconds,hits,hold_seconds',
         'rule.enabled' => 'required|boolean',
         'rule.mode' => 'required_if:rule.enabled,true|in:continuous,window',
         'rule.direction' => 'required_if:rule.enabled,true|in:upload,download,max',
         'rule.threshold_mbps' => 'required_if:rule.enabled,true|integer|min:1|max:100000',
         'rule.limit_mbps' => 'required_if:rule.enabled,true|integer|min:1|max:100000',
         'rule.duration_seconds' => 'sometimes|integer|min:3|max:3600',
         'rule.window_seconds' => 'sometimes|integer|min:3|max:3600',
         'rule.hits' => 'sometimes|integer|min:1|max:3600',
         'rule.hold_seconds' => 'required_if:rule.enabled,true|integer|min:1|max:86400',
        ]);
        if ($v->fails()) {
            throw ValidationException::withMessages(['auto_speed_limit' => $v->errors()->first()]);
        }
        if (!$rule['enabled']) {
            return null;
        }
        if ($rule['limit_mbps'] >= $rule['threshold_mbps'] || $base > 0 && $rule['threshold_mbps'] >= $base) {
            throw ValidationException::withMessages(['auto_speed_limit' => '临时上限必须小于触发阈值，触发阈值必须小于固定限速。']);
        }
        if ($rule['mode'] === 'continuous' && empty($rule['duration_seconds']) || $rule['mode'] === 'window' && (empty($rule['window_seconds']) || empty($rule['hits']) || $rule['hits'] > $rule['window_seconds'])) {
            throw ValidationException::withMessages(['auto_speed_limit' => '请填写有效的持续时长，或窗口秒数和超阈秒数。']);
        }
        $result = ['enabled' => true,'mode' => $rule['mode'],'direction' => $rule['direction']];
        foreach (['threshold_mbps','limit_mbps','duration_seconds','window_seconds','hits','hold_seconds'] as $key) {
            $result[$key] = (int)($rule[$key] ?? 0);
        }
        // 未使用条件仍保存有效默认值，切换触发方式时保持表单可编辑。
        $result['duration_seconds'] = $result['duration_seconds'] ?: 60;
        $result['window_seconds'] = $result['window_seconds'] ?: 60;
        $result['hits'] = $result['hits'] ?: 6;
        return $result;
    }
}
