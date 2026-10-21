<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeLimitReceipt extends Model
{
    protected $table = 'v2_node_limit_receipt';
    protected $guarded = ['id'];
    public $timestamps = false;
}
