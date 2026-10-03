<?php

namespace App\Services\TelegramBot;

use App\Exceptions\ApiException;
use App\Models\TelegramBotConfig;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ConfigService
{
    public const WEBHOOK_PATH = '/api/v1/guest/telegram-bot/webhook';

    public function __construct(private BotClient $client)
    {
    }

    public function current(): TelegramBotConfig
    {
        return TelegramBotConfig::findOrFail(1);
    }

    public function webhookUrl(TelegramBotConfig $config): string
    {
        return $config->webhook_url ?: rtrim((string) admin_setting('app_url', config('app.url')), '/') . self::WEBHOOK_PATH;
    }

    public function view(?TelegramBotConfig $config = null): array
    {
        $config ??= $this->current();
        return [
            'configured' => $config->getRawOriginal('token') !== null,
            'enabled' => $config->enabled,
            'webhook_registered' => $config->webhook_registered,
            'webhook_url' => $this->webhookUrl($config),
            'bot_username' => $config->bot_username,
            'webhook_matches' => $config->webhook_matches,
            'pending_update_count' => $config->pending_update_count,
            'last_checked_at' => $config->last_checked_at,
            'last_received_at' => $config->last_received_at,
            'last_error' => $config->last_error,
        ];
    }

    public function save(array $input): array
    {
        return $this->locked(function () use ($input) {
            $config = $this->current();
            if ($config->enabled || $config->webhook_registered) {
                throw new ApiException('请先停用机器人，再修改配置。', 409);
            }
            $url = trim($input['webhook_url'] ?? $this->webhookUrl($config));
            $this->validateUrl($url);
            $token = trim($input['token'] ?? '');
            if ($token !== '' && $token !== $config->token) {
                $config->token = $token;
                $config->webhook_secret = Str::random(48);
                $config->bot_id = null;
                $config->bot_username = null;
                $config->last_received_at = null;
            }
            $config->webhook_url = $url;
            $config->webhook_matches = null;
            $config->last_checked_at = null;
            $config->pending_update_count = null;
            $config->last_error = null;
            $config->save();
            return $this->view($config);
        });
    }

    public function check(): array
    {
        return $this->locked(function () {
            $config = $this->configured();
            try {
                $this->inspect($config);
                $config->save();
            } catch (ApiException $e) {
                $this->recordError($config, $e);
                throw $e;
            }
            return $this->view($config);
        });
    }

    public function enable(): array
    {
        return $this->locked(function () {
            $config = $this->configured();
            $this->validateUrl($this->webhookUrl($config));
            try {
                $remoteUrl = $this->inspect($config);
                if ($remoteUrl !== '' && !$config->webhook_matches) {
                    throw new ApiException('该机器人已由其他接收地址使用，请先停用原接入。', 409);
                }
                $config->webhook_secret ??= Str::random(48);
                $config->webhook_url = $this->webhookUrl($config);
                // 请求超时时无法确定远端是否生效，先保留待停用状态，禁止直接换密钥。
                $config->webhook_registered = true;
                $config->save();
                $this->client->request($config->token, 'setWebhook', [
                    'url' => $config->webhook_url,
                    'secret_token' => $config->webhook_secret,
                    'allowed_updates' => ['message', 'callback_query'],
                    'drop_pending_updates' => false,
                ]);
                $this->client->request($config->token, 'setMyCommands', [
                    'commands' => [
                        ['command' => 'start', 'description' => '打开账号菜单'],
                        ['command' => 'unbind', 'description' => '解除账号绑定'],
                    ],
                    'scope' => ['type' => 'all_private_chats'],
                ]);
                $config->enabled = true;
                $config->webhook_matches = true;
                $config->last_error = null;
                $config->save();
            } catch (ApiException $e) {
                $this->recordError($config, $e);
                throw $e;
            }
            return $this->view($config);
        });
    }

    public function sendTestMessage(string $recipient): void
    {
        $this->locked(function () use ($recipient) {
            $config = $this->configured();
            $limit = 'telegram-bot:test-message';
            if (RateLimiter::tooManyAttempts($limit, 1)) {
                throw new ApiException('请等待十秒后再发送测试消息。', 429);
            }
            $message = $this->client->request($config->token, 'sendMessage', [
                'chat_id' => $recipient,
                'text' => "这是一条 YZboard 测试消息。\n面板已成功发送消息到 Telegram。\n请回复 /start 检查消息接收。",
                'link_preview_options' => ['is_disabled' => true],
            ]);
            if (!is_array($message) || !is_int($message['message_id'] ?? null) || $message['message_id'] <= 0
                || ($message['chat']['id'] ?? null) !== (int) $recipient
                || ($message['chat']['type'] ?? null) !== 'private') {
                throw new ApiException('Telegram 未确认测试消息发送成功，请重新检查连接。', 502);
            }
            RateLimiter::hit($limit, 10);
        });
    }

    public function disable(): array
    {
        return $this->locked(function () {
            $config = $this->current();
            $config->enabled = false;
            $config->save();
            if (!$config->token) {
                return $this->view($config);
            }
            try {
                $remoteUrl = $this->inspect($config);
                if ($remoteUrl !== '' && $config->webhook_matches) {
                    $this->client->request($config->token, 'deleteWebhook', ['drop_pending_updates' => false]);
                }
                $config->webhook_registered = false;
                $config->webhook_matches = false;
                $config->last_error = null;
                $config->save();
            } catch (ApiException $e) {
                if ($e instanceof BotApiException && $e->telegramStatus === 401) {
                    // 原密钥已失效时无法移除远端地址，但必须允许管理员保存新密钥。
                    $config->webhook_registered = false;
                    $config->webhook_matches = null;
                    $config->webhook_secret = null;
                    $config->bot_id = null;
                    $config->bot_username = null;
                    $config->last_error = '原密钥已失效，已停止本地处理。远端接入未确认移除，请保存新密钥后重新接入。';
                    $config->last_checked_at = time();
                    $config->save();
                    return $this->view($config);
                }
                $this->recordError($config, $e);
                throw $e;
            }
            return $this->view($config);
        });
    }

    private function configured(): TelegramBotConfig
    {
        $config = $this->current();
        if (!$config->token) {
            throw new ApiException('请先保存机器人密钥。');
        }
        return $config;
    }

    private function inspect(TelegramBotConfig $config): string
    {
        $bot = $this->client->request($config->token, 'getMe');
        $webhook = $this->client->request($config->token, 'getWebhookInfo');
        if (!is_array($bot) || !is_int($bot['id'] ?? null) || $bot['id'] <= 0
            || ($bot['is_bot'] ?? false) !== true || !is_string($bot['username'] ?? null)
            || !is_array($webhook) || !is_string($webhook['url'] ?? null)) {
            throw new ApiException('Telegram 返回了无法识别的机器人状态。', 502);
        }
        $config->bot_id = $bot['id'];
        $config->bot_username = $bot['username'];
        $config->webhook_matches = hash_equals($this->webhookUrl($config), $webhook['url']);
        $config->pending_update_count = max(0, (int) ($webhook['pending_update_count'] ?? 0));
        $config->last_checked_at = time();
        $config->last_error = $this->deliveryError($config, $webhook);
        return $webhook['url'];
    }

    private function deliveryError(TelegramBotConfig $config, array $webhook): ?string
    {
        // 其他接收地址的错误不能归因于本面板；成功接收后的历史错误不再提示。
        if (!$config->webhook_matches || !is_string($webhook['last_error_message'] ?? null)
            || $webhook['last_error_message'] === '') {
            return null;
        }
        $errorAt = $webhook['last_error_date'] ?? null;
        if ($config->pending_update_count === 0 && is_int($errorAt)
            && $config->last_received_at !== null && $config->last_received_at > $errorAt) {
            return null;
        }

        // 只展示可识别的错误类别，远端原文可能含地址或认证信息，不能直接保存或回显。
        $error = $webhook['last_error_message'];
        if (preg_match('/\AWrong response from the webhook: ([1-5][0-9]{2})(?:\s|$)/i', $error, $match)) {
            $status = (int) $match[1];
            $hint = match (true) {
                $status === 403 => '请检查网站拦截规则或机器人来源校验。',
                $status === 404 => '请检查消息接收地址路径和反向代理配置。',
                $status === 429 => '请检查消息接收地址是否被限流。',
                $status >= 500 => '请检查面板服务和错误日志。',
                default => '请检查消息接收地址的跳转、访问验证和反向代理配置。',
            };
            return "Telegram 最近一次投递失败：接收地址返回 HTTP {$status}。{$hint}";
        }
        $hint = match (true) {
            preg_match('/SSL|TLS|certificate/i', $error) === 1 => 'HTTPS 握手或证书校验失败，请检查证书和 HTTPS 配置。',
            preg_match('/resolve|DNS/i', $error) === 1 => '域名解析失败，请检查消息接收域名。',
            preg_match('/timed?\s*out|timeout/i', $error) === 1 => '连接或响应超时，请检查面板入口和服务响应。',
            preg_match('/connection refused/i', $error) === 1 => '连接被拒绝，请检查 HTTPS 监听和防火墙。',
            default => '请检查面板入口和服务日志。',
        };
        return 'Telegram 最近一次投递失败：' . $hint;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_ends_with($parts['path'] ?? '', self::WEBHOOK_PATH)) {
            throw new ApiException('请填写本面板的 HTTPS 消息接收地址。');
        }
    }

    private function recordError(TelegramBotConfig $config, ApiException $error): void
    {
        $config->last_error = $error->getMessage();
        $config->last_checked_at = time();
        $config->save();
    }

    private function locked(callable $callback): mixed
    {
        try {
            return Cache::lock('telegram-bot:config', 90)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw new ApiException('机器人设置正在更新，请稍后重试。', 409);
        }
    }
}
