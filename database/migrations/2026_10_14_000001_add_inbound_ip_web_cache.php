<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_inbound_ip', function (Blueprint $table) {
            $table->text('web_data')->nullable();
            $table->unsignedInteger('web_expires_at')->default(0);
            $table->unsignedInteger('web_lookup_after')->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_inbound_ip', function (Blueprint $table) {
            $table->dropIndex(['web_lookup_after']);
            $table->dropColumn(['web_data', 'web_expires_at', 'web_lookup_after']);
        });
    }
};
