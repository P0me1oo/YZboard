<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBotBinding extends Model
{
    protected $table = 'v2_telegram_bot_bindings';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['unbind_token', 'reset_token'];
    protected $casts = [
        'user_id' => 'integer',
        'telegram_id' => 'integer',
        'unbind_token' => 'encrypted',
        'unbind_expires_at' => 'integer',
        'reset_token' => 'encrypted',
        'reset_expires_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
