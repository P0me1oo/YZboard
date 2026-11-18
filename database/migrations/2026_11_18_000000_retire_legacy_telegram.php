<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_plugins')) DB::table('v2_plugins')->whereRaw('LOWER(code) = ?', ['telegram'])->delete();
        if (Schema::hasTable('v2_settings')) DB::table('v2_settings')->whereIn('name', [
            'telegram_bot_enable', 'telegram_bot_token', 'telegram_webhook_url',
            'telegram_discuss_id', 'telegram_channel_id', 'telegram_discuss_link',
        ])->delete();
        app()->forgetInstance(\App\Support\Setting::class);
        \Illuminate\Support\Facades\Cache::store('redis')->forget(\App\Support\Setting::CACHE_KEY);
    }

    public function down(): void
    {
        // 已弃用的令牌和安装记录不恢复；独立机器人的表和绑定没有修改。
    }
};
