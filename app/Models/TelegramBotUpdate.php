<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBotUpdate extends Model
{
    protected $table = 'v2_telegram_bot_updates';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'bot_id' => 'integer',
        'update_id' => 'integer',
        'telegram_id' => 'integer',
        'binding_id' => 'integer',
        'sent_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
