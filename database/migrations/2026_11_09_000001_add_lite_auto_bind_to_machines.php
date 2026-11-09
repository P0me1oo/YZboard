<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->boolean('lite_auto_bind')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', fn (Blueprint $table) => $table->dropColumn('lite_auto_bind'));
    }
};
