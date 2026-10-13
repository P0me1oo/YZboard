<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeReportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    public const PAYLOAD_RETENTION_DAYS = 7;

    protected $table = 'v2_node_report_batch';

    protected $guarded = ['id'];

    protected $casts = [
        'server_snapshot' => 'array',
        'traffic' => 'array',
        'relay_traffic' => 'array',
        'relay_user_traffic' => 'array',
        'record_at' => 'integer',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
        // 0 表示旧报告尚未登记；正数为清理时间；null 表示详细内容已清空。
        'payload_cleanup_at' => 'integer',
    ];
}
