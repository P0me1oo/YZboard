<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->json('agent_runtime')->nullable();
            $table->json('agent_operation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['agent_runtime', 'agent_operation']);
        });
    }
};
