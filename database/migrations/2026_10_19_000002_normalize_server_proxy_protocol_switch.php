<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 旧空值按原界面显示转换；不再根据前置名单决定接收行为。
        DB::table('v2_server')->whereNull('accept_proxy_protocol')
            ->select(['id', 'protocol_settings'])->chunkById(200, function ($servers): void {
                foreach ($servers as $server) {
                    $settings = json_decode($server->protocol_settings ?? '{}', true) ?? [];
                    DB::table('v2_server')->where('id', $server->id)
                        ->whereNull('accept_proxy_protocol')->update([
                            'accept_proxy_protocol' => data_get($settings, 'network_settings.acceptProxyProtocol') === true,
                        ]);
                }
            });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->boolean('accept_proxy_protocol')->nullable(false)->default(false)->change();
        });
    }

    public function down(): void
    {
        // 保留已经明确的开关值，回退仅恢复列定义。
        Schema::table('v2_server', function (Blueprint $table) {
            $table->boolean('accept_proxy_protocol')->nullable()->default(null)->change();
        });
    }
};
