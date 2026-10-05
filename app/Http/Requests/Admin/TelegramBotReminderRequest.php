<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TelegramBotReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remind_expiring' => ['required', 'boolean'],
            'remind_expired' => ['required', 'boolean'],
            'remind_device' => ['required', 'boolean'],
            'remind_connection' => ['required', 'boolean'],
            'remind_days' => ['required', 'integer', 'between:1,365'],
            'remind_interval_minutes' => ['required', 'integer', 'between:1,10080'],
        ];
    }

    public function attributes(): array
    {
        return [
            'remind_expiring' => '到期提醒', 'remind_expired' => '过期提醒',
            'remind_device' => '设备数超限提醒', 'remind_connection' => '连接数超限提醒',
            'remind_days' => '提前提醒天数', 'remind_interval_minutes' => '超限提醒间隔（分钟）',
        ];
    }
}
