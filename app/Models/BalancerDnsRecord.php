<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalancerDnsRecord extends Model
{
    protected $table = 'v2_balancer_dns_record';
    protected $guarded = ['id'];
    public $timestamps = false;
}
