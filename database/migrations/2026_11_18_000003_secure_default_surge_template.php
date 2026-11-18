<?php

use App\Models\SubscribeTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = DB::table('v2_subscribe_templates')->where('name', 'surge');
        $content = $templates->value('content');
        if (!is_string($content) || hash('sha256', str_replace("\r\n", "\n", $content)) !== '6281c6e37762277a2a3ba07df1e9f9a013c17af40be10200e199ad96cabd95d6') {
            return;
        }
        $templates->where('content', $content)->update([
            'content' => file_get_contents(resource_path('rules/default.surge.conf')),
            'updated_at' => now(),
        ]);
        SubscribeTemplate::flushCache('surge');
    }

    public function down(): void
    {
        // 回退应用版本时仍保留安全的模板默认值。
    }
};
