<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DdnsBinding extends Model
{
    protected $table = 'v2_ddns_binding';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'retired' => 'boolean', 'cleanup_requested' => 'boolean', 'synced_at' => 'datetime'];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(BalancerDnsPreset::class, 'preset_id');
    }
}
