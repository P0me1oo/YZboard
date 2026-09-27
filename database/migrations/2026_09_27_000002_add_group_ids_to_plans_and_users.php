<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->json('group_ids')->nullable()->after('group_id');
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->json('group_ids')->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('group_ids'));
        Schema::table('v2_plan', fn (Blueprint $table) => $table->dropColumn('group_ids'));
    }
};
