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
        'auto_approve_join' => 'boolean',
        'join_group_ids' => 'array',
        'notify_node_offline' => 'boolean',
        'notify_xray_crash' => 'boolean',
        'node_offline_minutes' => 'integer',
        'notify_ticket' => 'boolean',
        'allow_ticket_reply' => 'boolean',
        'notify_payment' => 'boolean',
        'notify_admin_device' => 'boolean',
        'notify_admin_connection' => 'boolean',
        'notify_admin_recipients' => 'array',
        'notify_admin_interval_minutes' => 'integer',
        'remind_expiring' => 'boolean',
        'remind_expired' => 'boolean',
        'remind_device' => 'boolean',
        'remind_connection' => 'boolean',
        'remind_days' => 'integer',
        'remind_interval_minutes' => 'integer',
        'remind_expired_since' => 'integer',
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
