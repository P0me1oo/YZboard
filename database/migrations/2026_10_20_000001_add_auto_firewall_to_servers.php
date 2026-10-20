<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('v2_server', 'auto_firewall')) {
            return;
        }
        Schema::table('v2_server', function (Blueprint $table) {
            $table->boolean('auto_firewall')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('auto_firewall'));
    }
};
