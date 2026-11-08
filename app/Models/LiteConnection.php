<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiteConnection extends Model
{
    protected $table = 'v2_lite_connection';
    protected $guarded = [];
    protected $hidden = ['api_key'];
    protected $casts = ['api_key' => 'encrypted', 'enabled' => 'boolean'];
}
