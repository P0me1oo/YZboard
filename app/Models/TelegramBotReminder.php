<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBotReminder extends Model
{
    protected $table = 'v2_telegram_bot_reminders';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'binding_id' => 'integer', 'cycle' => 'integer', 'details' => 'array',
        'sent_at' => 'integer', 'attempts' => 'integer', 'retry_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ];
}
