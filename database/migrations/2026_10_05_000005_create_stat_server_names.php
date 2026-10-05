<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_stat_server_name', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->primary();
            $table->string('name');
            $table->unsignedInteger('deleted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_server_name');
    }
};
