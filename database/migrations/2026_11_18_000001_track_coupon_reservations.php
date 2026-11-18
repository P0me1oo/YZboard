<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_order', 'coupon_reserved')) Schema::table('v2_order', function (Blueprint $table) {
            $table->boolean('coupon_reserved')->default(false);
        });
        DB::table('v2_order')->where('status', 0)->whereNotNull('coupon_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('v2_coupon')
                ->whereColumn('v2_coupon.id', 'v2_order.coupon_id')->whereNotNull('limit_use'))
            ->update(['coupon_reserved' => true]);
    }

    public function down(): void
    {
        Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn('coupon_reserved'));
    }
};
