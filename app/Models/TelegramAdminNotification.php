<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAdminNotification extends Model
{
    protected $table = 'v2_telegram_admin_notifications';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer', 'telegram_id' => 'integer', 'details' => 'array',
        'sent_at' => 'integer', 'attempts' => 'integer', 'retry_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
