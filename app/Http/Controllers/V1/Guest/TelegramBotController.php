<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TelegramBotConfig;
use App\Services\TelegramBot\ConfigService;
use App\Services\TelegramBot\UpdateDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramBotController extends Controller
{
    public function webhook(Request $request, ConfigService $configs, UpdateDispatcher $dispatcher): JsonResponse
    {
        try {
            $config = $configs->current();
            $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (!$config->webhook_secret || !hash_equals($config->webhook_secret, $secret)) {
                return response()->json(['message' => '请求来源无效。'], 403);
            }
            if (!$config->enabled || !$config->token || !$config->bot_id) {
                return response()->json(['message' => '机器人暂未启用。'], 503);
            }
            if (strlen($request->getContent()) > 65536) {
                return response()->json(['message' => '消息过长。'], 413);
            }
            $update = $request->json()->all();
            if (!is_int($update['update_id'] ?? null) || $update['update_id'] < 0) {
                return response()->json(['message' => '消息格式无效。'], 400);
            }
            TelegramBotConfig::whereKey(1)->update(['last_received_at' => time()]);
            $dispatcher->handle($update, $config);
            return response()->json(['ok' => true]);
        } catch (ApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\Throwable $e) {
            // 数据库异常也可能带出订阅标识，只记录异常类型。
            Log::warning('Telegram Bot 消息处理未完成', ['exception' => $e::class]);
            return response()->json(['message' => '消息暂未处理，请稍后重试。'], 503);
        }
    }
}
