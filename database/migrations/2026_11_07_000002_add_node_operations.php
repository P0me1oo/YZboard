<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['v2_plan','v2_user'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->json('auto_speed_limit')->nullable());
        }
        Schema::table('v2_server', function (Blueprint $t) {
            $t->json('health_check')->nullable();
            $t->string('certificate_renew', 64)->nullable();
        });
        Schema::create('v2_node_health_sample', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('server_id');
            $t->string('sample_id', 64);
            $t->unsignedBigInteger('checked_at');
            $t->string('status', 24);
            $t->unsignedInteger('latency_ms')->nullable();
            $t->json('detail');
            $t->unique(['server_id','sample_id']);
            $t->index(['server_id','checked_at']);
            $t->index('checked_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_node_health_sample');
        Schema::table('v2_server', fn (Blueprint $t) => $t->dropColumn(['health_check','certificate_renew']));
        foreach (['v2_plan','v2_user'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('auto_speed_limit'));
        }
    }
};
