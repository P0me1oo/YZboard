<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * App\Models\ServerGroup
 *
 * @property int $id
 * @property string $name 分组名
 * @property int|null $sort 排序值，越小越靠前
 * @property int $created_at
 * @property int $updated_at
 * @property-read int $server_count 服务器数量
 */
class ServerGroup extends Model
{
    protected $table = 'v2_server_group';
    protected $dateFormat = 'U';
    protected $casts = [
        'sort' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    /**
     * 统一的展示顺序：先按管理端排序值升序，未设置排序的排在最后，再按 id 倒序兜底。
     * 权限组列表、节点编辑的权限组选择等入口都走这里，保证顺序一致。
     */
    public function scopeOrderedForDisplay(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN sort IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')
            ->orderByDesc('id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'group_id', 'id');
    }

    public function servers()
    {
        return Server::whereJsonContains('group_ids', (string) $this->id)->get();
    }

    /**
     * 获取服务器数量
     */
    protected function serverCount(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::whereJsonContains('group_ids', (string) $this->id)->count(),
        );
    }
}
