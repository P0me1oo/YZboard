<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DdnsRecord extends Model
{
    protected $table = 'v2_ddns_record';
    protected $guarded = ['id'];
    public $timestamps = false;
}
