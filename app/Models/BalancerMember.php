<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalancerMember extends Model
{
    protected $table = 'v2_balancer_member';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['priority' => 'integer', 'position' => 'integer', 'last_report_at' => 'integer'];

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_node_id');
    }

    public function runtimeNode(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'runtime_node_id');
    }
}
