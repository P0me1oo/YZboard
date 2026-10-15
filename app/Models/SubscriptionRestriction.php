<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRestriction extends Model
{
    protected $table = 'v2_subscription_restrictions';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['count' => 'integer', 'triggered_at' => 'integer', 'expires_at' => 'integer'];
}
