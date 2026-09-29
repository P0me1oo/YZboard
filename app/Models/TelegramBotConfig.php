<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBotConfig extends Model
{
    protected $table = 'v2_telegram_bot_configs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['token', 'webhook_secret'];
    protected $casts = [
        'token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'bot_id' => 'integer',
        'enabled' => 'boolean',
        'webhook_registered' => 'boolean',
        'webhook_matches' => 'boolean',
        'pending_update_count' => 'integer',
        'last_checked_at' => 'integer',
        'last_received_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
