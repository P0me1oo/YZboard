<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TelegramBotConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'min:20', 'max:256', 'regex:/\A\d+:[A-Za-z0-9_-]+\z/'],
            'webhook_base_url' => ['required_without:webhook_url', 'prohibits:webhook_url', 'string', 'max:2000', 'url:https'],
            'webhook_url' => ['required_without:webhook_base_url', 'prohibits:webhook_base_url', 'string', 'max:2048', 'url:https'],
        ];
    }

    public function attributes(): array
    {
        return ['token' => '机器人令牌', 'webhook_base_url' => 'Webhook Base URL', 'webhook_url' => '消息接收地址'];
    }
}
