<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // 旧节点默认不启用来源限制。
            $table->json('source_policy')->nullable()->comment('来源地区拦截与例外 IP');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('source_policy'));
    }
};
