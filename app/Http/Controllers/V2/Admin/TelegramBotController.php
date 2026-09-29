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
            });
        }
        $page = $query->paginate($input['per_page'] ?? 20);
        return response()->json([
            'data' => $page->getCollection()->map(fn (TelegramBotBinding $binding) => [
                'id' => $binding->id,
                'user_id' => $binding->user_id,
                'email' => $binding->user?->email,
                'telegram_id' => (string) $binding->telegram_id,
                'created_at' => $binding->created_at,
            ]),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]);
    }
}
