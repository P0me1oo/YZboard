<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_binding_deletion', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->primary();
            $table->char('token_hash', 64);
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_binding_deletion');
    }
};
