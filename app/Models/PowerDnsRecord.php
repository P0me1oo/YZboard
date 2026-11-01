<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerDnsRecord extends Model
{
    protected $table = 'v2_powerdns_record';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['contents' => 'array', 'confirmed' => 'boolean'];
}
