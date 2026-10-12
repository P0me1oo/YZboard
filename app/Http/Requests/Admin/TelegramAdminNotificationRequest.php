<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TelegramAdminNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notify_admin_device' => ['required', 'boolean'],
            'notify_admin_connection' => ['required', 'boolean'],
            'notify_admin_interval_minutes' => ['required', 'integer', 'between:1,10080'],
            'notify_admin_recipients' => ['present', 'array', 'max:20',
                'min:' . (($this->boolean('notify_admin_device') || $this->boolean('notify_admin_connection')) ? 1 : 0)],
            'notify_admin_recipients.*' => ['required', 'integer', 'min:1', 'max:4503599627370495',
                'regex:/\A[1-9][0-9]{0,15}\z/', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'notify_admin_device' => '管理员设备数告警',
            'notify_admin_connection' => '管理员连接数告警',
            'notify_admin_interval_minutes' => '重复告警间隔（分钟）',
            'notify_admin_recipients' => '管理员接收人',
            'notify_admin_recipients.*' => '管理员 Telegram 数字 ID',
        ];
    }
}
