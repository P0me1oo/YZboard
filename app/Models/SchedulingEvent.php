<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulingEvent extends Model
{
    protected $table = 'v2_scheduling_event';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['previous' => 'array', 'current' => 'array', 'created_at' => 'datetime'];
}
