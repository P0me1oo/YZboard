<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBusinessNotification extends Model
{
    protected $table = 'v2_telegram_business_notifications';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'bot_id' => 'integer', 'binding_id' => 'integer', 'telegram_id' => 'integer',
        'event_id' => 'integer', 'telegram_message_id' => 'integer', 'sent_at' => 'integer',
        'attempts' => 'integer', 'retry_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ];
}
