<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_balancer_pool', function (Blueprint $table) {
            $table->text('scheduling_config')->nullable();
            $table->text('scheduling_state')->nullable();
        });
        Schema::table('v2_balancer_member', fn (Blueprint $table) => $table->text('scheduling_state')->nullable());
        Schema::create('v2_landing_pool', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('node_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->text('members');
            $table->text('state')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_scheduling_event', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->string('pool_name', 100);
            $table->string('reason', 40);
            $table->text('previous')->nullable();
            $table->text('current')->nullable();
            $table->timestamp('created_at');
            $table->index(['owner_type', 'owner_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('v2_landing_pool')->where('enabled', true)->exists()) throw new RuntimeException('回退前请停用落地主备池');
        foreach (DB::table('v2_balancer_pool')->where('enabled', true)->get(['scheduling_config']) as $pool) {
            $policy = json_decode($pool->scheduling_config ?? 'null', true) ?? [];
            if (($policy['health_enabled'] ?? false) || ($policy['quota_enabled'] ?? false) || ($policy['strategy'] ?? 'priority') !== 'priority') throw new RuntimeException('回退前请停用前置自动调度策略');
        }
        Schema::dropIfExists('v2_scheduling_event');
        Schema::dropIfExists('v2_landing_pool');
        Schema::table('v2_balancer_member', fn (Blueprint $table) => $table->dropColumn('scheduling_state'));
        Schema::table('v2_balancer_pool', fn (Blueprint $table) => $table->dropColumn(['scheduling_config', 'scheduling_state']));
    }
};
