<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_traffic_grant', function (Blueprint $table) {
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('machine_id')->default(0);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('expires_at')->index();
            $table->primary(['server_id', 'machine_id', 'user_id'], 'node_traffic_grant_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_traffic_grant');
    }
};
