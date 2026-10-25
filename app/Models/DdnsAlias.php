<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DdnsAlias extends Model
{
    protected $table = 'v2_ddns_alias';
    protected $guarded = ['id'];
    public $timestamps = false;
}
