<?php

namespace App\Services\TelegramBot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BotClient
{
    public function request(string $token, string $method, array $parameters = []): mixed
    {
        try {
            $response = Http::asJson()->connectTimeout(3)->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $parameters);
        } catch (\Throwable) {
            // HTTP 异常可能包含带密钥的 URL，不能记录或透传原异常。
            Log::warning('Telegram Bot 请求未完成', ['method' => $method]);
            throw new BotApiException('无法连接 Telegram，请稍后重试。');
        }

        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['ok'] ?? false) !== true) {
            if ($method === 'editMessageText' && ($data['error_code'] ?? null) === 400
                && str_starts_with((string) ($data['description'] ?? ''), 'Bad Request: message is not modified')) {
                return true;
            }
            Log::warning('Telegram Bot 请求被拒绝', ['method' => $method, 'status' => $response->status()]);
            $status = is_int($data['error_code'] ?? null) ? $data['error_code'] : $response->status();
            $message = match ($status) {
                400 => $method === 'sendMessage'
                    ? '无法发送消息，请核对接收人的 Telegram 数字 ID，并先私聊机器人发送 /start。'
                    : 'Telegram 未接受请求，请检查机器人设置后重试。',
                401 => '机器人密钥无效，请检查设置。',
                403 => $method === 'sendMessage'
                    ? '机器人无法向该账号发送消息，请先私聊机器人发送 /start，并确认没有屏蔽机器人。'
                    : 'Telegram 未接受请求，请检查机器人设置后重试。',
                429 => 'Telegram 请求过于频繁，请稍后重试。',
                default => 'Telegram 未接受请求，请检查机器人设置后重试。',
            };
            throw new BotApiException($message, $status);
        }
        if (in_array($method, ['setWebhook', 'deleteWebhook', 'setMyCommands', 'answerCallbackQuery'], true)
            && ($data['result'] ?? null) !== true) {
            throw new BotApiException('Telegram 未确认操作成功，请重新检查连接。');
        }

        return $data['result'] ?? true;
    }

    public function reply(string $token, array $context, array $message): void
    {
        $parameters = [
            'chat_id' => $context['chat_id'],
            'text' => $message['text'],
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => $message['keyboard'] ?? []],
        ];
        $method = 'sendMessage';
        if ($context['callback_id'] !== null) {
            $method = 'editMessageText';
            $parameters['message_id'] = $context['message_id'];
        }
        $this->request($token, $method, $parameters);
    }

    public function answerCallback(string $token, string $callbackId): void
    {
        $this->request($token, 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }
}
