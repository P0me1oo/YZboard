<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_balancer_dns_preset', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('domain', 253);
            $table->string('zone_id', 32);
            $table->text('api_token');
            $table->timestamps();
        });
        Schema::create('v2_balancer_pool', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('domain', 253)->unique();
            $table->unsignedBigInteger('dns_preset_id')->nullable()->index();
            $table->unsignedBigInteger('gateway_machine_id')->index();
            $table->unsignedBigInteger('gateway_node_id')->nullable()->index();
            $table->string('gateway_address', 45);
            $table->unsignedInteger('gateway_port');
            $table->unsignedInteger('backend_port');
            $table->string('protocol', 20);
            $table->unsignedBigInteger('template_node_id');
            $table->longText('template_config');
            $table->boolean('enabled')->default(true);
            $table->json('gateway_source_ips')->nullable();
            $table->text('dns_error')->nullable();
            $table->timestamp('dns_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_balancer_member', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pool_id')->index();
            $table->unsignedBigInteger('source_node_id')->index();
            $table->unsignedBigInteger('runtime_node_id')->nullable()->index();
            $table->unsignedInteger('priority')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('last_report_at')->nullable();
            $table->unique(['pool_id', 'source_node_id']);
        });
        Schema::create('v2_balancer_dns_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pool_id')->index();
            $table->string('record_id', 64)->unique();
            $table->string('zone_id', 32);
            $table->string('name', 253);
            $table->string('type', 4);
            $table->string('content', 45);
        });
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedBigInteger('relay_pool_id')->nullable()->index();
            $table->unsignedBigInteger('balancer_pool_id')->nullable()->index();
            $table->string('balancer_role', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn(['relay_pool_id', 'balancer_pool_id', 'balancer_role']);
        });
        Schema::dropIfExists('v2_balancer_dns_record');
        Schema::dropIfExists('v2_balancer_member');
        Schema::dropIfExists('v2_balancer_pool');
        Schema::dropIfExists('v2_balancer_dns_preset');
    }
};
