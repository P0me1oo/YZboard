<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TelegramBusinessNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notify_ticket' => ['required', 'boolean'],
            'allow_ticket_reply' => ['required', 'boolean'],
            'notify_payment' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['notify_ticket' => '工单通知', 'allow_ticket_reply' => '工单回复', 'notify_payment' => '支付通知'];
    }
}
