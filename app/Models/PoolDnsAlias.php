<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoolDnsAlias extends Model
{
    protected $table = 'v2_pool_dns_alias';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['member_id' => 'integer', 'assignment_state' => 'array'];
}
