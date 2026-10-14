<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 重复执行时保留新版已编辑或已清空的链接。
        if (Schema::hasColumn('v2_telegram_bot_configs', 'group_link')) return;
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->string('group_link', 2048)->nullable();
        });
        $value = DB::table('v2_settings')->where('name', 'telegram_discuss_link')->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;
        $link = is_string($decoded) ? $decoded : $value;
        if (is_string($link) && trim($link) !== '') {
            DB::table('v2_telegram_bot_configs')->where('id', 1)->update(['group_link' => trim($link)]);
        }
    }

    public function down(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn('group_link');
        });
    }
};
