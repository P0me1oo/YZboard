<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TelegramBotConfigRequest;
use App\Models\TelegramBotBinding;
use App\Services\TelegramBot\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramBotController extends Controller
{
    public function __construct(private ConfigService $config)
    {
    }

    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->config->view()]);
    }

    public function token(): JsonResponse
    {
        return response()->json(['data' => ['token' => $this->config->current()->token]])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function save(TelegramBotConfigRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->config->save($request->validated())]);
    }

    public function check(): JsonResponse
    {
        return response()->json(['data' => $this->config->check()]);
    }

    public function enable(): JsonResponse
    {
        return response()->json(['data' => $this->config->enable()]);
    }

    public function testMessage(Request $request): JsonResponse
    {
        $input = $request->validate([
            'telegram_id' => ['required', 'integer', 'min:1', 'max:4503599627370495', 'regex:/\A[1-9][0-9]{0,15}\z/'],
        ], [
            'telegram_id.*' => '请输入接收人的 Telegram 数字 ID，不支持用户名或群组。',
        ]);
        $this->config->sendTestMessage((string) $input['telegram_id']);
        return response()->json(['data' => ['sent' => true]]);
    }

    public function disable(): JsonResponse
    {
        return response()->json(['data' => $this->config->disable()]);
    }

    public function bindings(Request $request): JsonResponse
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = TelegramBotBinding::with('user:id,email')->orderByDesc('id');
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereHas('user', fn ($users) => $users->where('email', 'like', '%' . $search . '%'));
                if (ctype_digit($search) && strlen($search) <= 18) {
                    $query->orWhere('telegram_id', $search);
                }
                $username = str_starts_with($search, '@') ? substr($search, 1) : $search;
                if (preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $username)) {
                    // 用户名不区分大小写，下划线按原字符查询。
                    $query->orWhereRaw("LOWER(telegram_username) LIKE ? ESCAPE '!'", [
                        '%' . str_replace('_', '!_', strtolower($username)) . '%',
                    ]);
                }
            });
        }
        $page = $query->paginate($input['per_page'] ?? 20);
        return response()->json([
            'data' => $page->getCollection()->map(fn (TelegramBotBinding $binding) => [
                'id' => $binding->id,
                'user_id' => $binding->user_id,
                'email' => $binding->user?->email,
                'telegram_id' => (string) $binding->telegram_id,
                'telegram_username' => $binding->telegram_username,
                'created_at' => $binding->created_at,
            ]),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]);
    }
}
