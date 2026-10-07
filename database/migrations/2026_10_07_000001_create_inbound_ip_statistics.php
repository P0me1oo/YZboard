<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_inbound_ip', function (Blueprint $table) {
            $table->string('ip', 45)->primary();
            $table->char('ip_key', 32);
            $table->unsignedTinyInteger('ip_version');
            $table->string('region', 512)->default('未知');
            $table->string('province', 128)->default('未知');
            $table->string('asn', 16)->nullable();
            $table->string('as_name', 200)->nullable();
            $table->unsignedInteger('lookup_after')->default(0)->index();
            $table->unsignedInteger('local_expires_at')->default(0);
            $table->unsignedInteger('external_expires_at')->default(0);
        });
        Schema::create('v2_stat_user_inbound_ip', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->string('ip', 45);
            $table->unsignedInteger('record_at');
            $table->unsignedInteger('first_seen_at');
            $table->unsignedInteger('last_seen_at');
            $table->primary(['user_id', 'ip', 'record_at'], 'stat_user_inbound_ip_primary');
            $table->index(['record_at', 'user_id'], 'stat_user_inbound_ip_range');
            $table->index(['ip', 'record_at'], 'stat_user_inbound_ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_user_inbound_ip');
        Schema::dropIfExists('v2_inbound_ip');
    }
};
