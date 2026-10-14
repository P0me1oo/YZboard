<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TelegramGroupNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_link' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'auto_approve_join' => ['required', 'boolean'],
            'join_group_ids' => ['present', 'array', 'max:20', $this->boolean('auto_approve_join') ? 'min:1' : 'min:0'],
            'join_group_ids.*' => ['required', 'string', 'distinct', 'regex:/\A-[1-9][0-9]{0,15}\z/', 'numeric', 'min:-4503599627370495', 'max:-1'],
            'notify_node_offline' => ['required', 'boolean'],
            'node_offline_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    public function attributes(): array
    {
        return ['group_link' => 'Telegram 群组链接', 'auto_approve_join' => '自动审核入群', 'join_group_ids' => '审核群组',
            'join_group_ids.*' => '群组 ID', 'notify_node_offline' => '节点掉线通知',
            'node_offline_minutes' => '掉线等待分钟数'];
    }
}
