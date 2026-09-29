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
            'webhook_url' => ['required', 'string', 'max:2048', 'url:https'],
        ];
    }

    public function attributes(): array
    {
        return ['token' => '机器人密钥', 'webhook_url' => '消息接收地址'];
    }
}
