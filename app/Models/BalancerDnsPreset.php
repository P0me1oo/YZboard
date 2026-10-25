<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalancerDnsPreset extends Model
{
    protected $table = 'v2_balancer_dns_preset';
    protected $guarded = ['id'];
    protected $hidden = ['api_token'];
    protected $casts = ['api_token' => 'encrypted'];
}
