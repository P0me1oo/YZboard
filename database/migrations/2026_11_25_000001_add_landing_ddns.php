<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_ddns_binding', function (Blueprint $table) {
            $table->string('scope', 16)->default('entry');
            $table->string('address_source', 20)->default('manual');
            $table->string('confirmed_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_ddns_binding', fn (Blueprint $table) => $table->dropColumn(['scope', 'address_source', 'confirmed_address']));
    }
};
