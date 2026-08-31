<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeReportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'v2_node_report_batch';

    protected $guarded = ['id'];

    protected $casts = [
        'server_snapshot' => 'array',
        'traffic' => 'array',
        'relay_traffic' => 'array',
        'record_at' => 'integer',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];
}
