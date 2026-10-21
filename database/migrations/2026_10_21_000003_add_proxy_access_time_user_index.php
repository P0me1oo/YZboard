<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_proxy_access_log', function (Blueprint $table) {
            $table->index(['connected_at', 'user_id', 'id'], 'proxy_access_time_user');
        });
    }

    public function down(): void
    {
        Schema::table('v2_proxy_access_log', function (Blueprint $table) {
            $table->dropIndex('proxy_access_time_user');
        });
    }
};
