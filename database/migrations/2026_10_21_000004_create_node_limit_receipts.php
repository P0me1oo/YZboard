<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_limit_receipt', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->char('report_key', 64);
            $table->unsignedInteger('accepted_at')->index();
            $table->unique(['node_id', 'report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_limit_receipt');
    }
};
