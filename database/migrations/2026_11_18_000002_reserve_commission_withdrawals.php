<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->unsignedBigInteger('withdraw_amount')->nullable();
            $table->string('withdraw_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_ticket', fn (Blueprint $table) => $table->dropColumn(['withdraw_amount', 'withdraw_status']));
    }
};
