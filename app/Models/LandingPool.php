<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPool extends Model
{
    protected $table = 'v2_landing_pool';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'members' => 'array', 'state' => 'array', 'node_id' => 'integer'];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'node_id');
    }
}
