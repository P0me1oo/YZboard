<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_node_report_batch', 'relay_user_traffic')) {
            Schema::table('v2_node_report_batch', function (Blueprint $table): void {
                $table->json('relay_user_traffic')->nullable()->after('relay_traffic');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_node_report_batch', 'relay_user_traffic')) {
            Schema::table('v2_node_report_batch', function (Blueprint $table): void {
                $table->dropColumn('relay_user_traffic');
            });
        }
    }
};
