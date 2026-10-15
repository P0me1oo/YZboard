<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class SubscriptionProtectionController extends Controller
{
    /** 白名单选择器只返回用户编号和邮箱，避免传送订阅凭据。 */
    public function users(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|string|max:254',
            'ids' => 'sometimes|array|list|max:1000',
            'ids.*' => 'integer|min:1',
        ]);
        $query = User::query()->select(['id', 'email']);
        if (array_key_exists('ids', $data)) {
            $query->whereIn('id', $data['ids']);
        } else {
            $search = trim($data['search'] ?? '');
            if ($search === '') {
                return $this->success([]);
            }
            $query->where(function ($query) use ($search) {
                // 显式转义 LIKE 通配符，让输入按字面匹配。
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
                $query->whereRaw("email LIKE ? ESCAPE '!'", [$pattern]);
                if (ctype_digit($search) && strlen($search) <= 10) {
                    $query->orWhere('id', (int) $search);
                }
            })->limit(20);
        }
        return $this->success($query->orderBy('id')->get());
    }
}
