<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BalancerPool extends Model
{
    protected $table = 'v2_balancer_pool';
    protected $guarded = ['id'];
    protected $hidden = ['template_config'];
    protected $casts = ['enabled' => 'boolean', 'gateway_source_ips' => 'array', 'template_config' => 'encrypted:array', 'dns_synced_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(BalancerMember::class, 'pool_id')->orderBy('position')->orderBy('id');
    }

    public function dnsPreset(): BelongsTo
    {
        return $this->belongsTo(BalancerDnsPreset::class, 'dns_preset_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'gateway_node_id');
    }
}
