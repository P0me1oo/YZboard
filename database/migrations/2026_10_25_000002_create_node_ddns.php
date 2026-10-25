<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_ddns_binding', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->index(['owner_type', 'owner_id']);
            $table->unsignedBigInteger('preset_id')->index();
            $table->string('prefix', 63);
            $table->string('domain', 220)->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('retired')->default(false);
            $table->boolean('cleanup_requested')->default(false);
            $table->string('fingerprint', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_ddns_alias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('binding_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('label', 32);
            $table->unique(['binding_id', 'user_id']);
            $table->unique(['binding_id', 'label']);
        });
        Schema::create('v2_ddns_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('binding_id')->index();
            $table->unsignedBigInteger('alias_id')->nullable()->index();
            $table->string('name', 253)->unique();
            $table->string('type', 5);
            $table->string('content', 253);
            $table->string('record_id', 64)->nullable();
            $table->string('marker', 48);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ddns_record');
        Schema::dropIfExists('v2_ddns_alias');
        Schema::dropIfExists('v2_ddns_binding');
    }
};
