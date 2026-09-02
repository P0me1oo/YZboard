<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // null 表示沿用机器默认值；当前机器默认值为 xray。
            $table->string('kernel_type', 16)->nullable()->after('machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('kernel_type');
        });
    }
};
