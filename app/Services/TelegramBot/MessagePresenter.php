<?php

namespace App\Services\TelegramBot;

use App\Models\TelegramBotBinding;
use App\Models\User;
use App\Utils\Helper;

class MessagePresenter
{
    public function render(string $action, ?TelegramBotBinding $binding): array
    {
        $notices = [
            'private_only' => '请私聊机器人后发送 /start。',
            'invalid_link' => '订阅链接无效，请发送本面板当前的订阅链接。',
            'occupied' => '该账号或当前 Telegram 已有绑定，请先解除原绑定。',
            'limited' => '绑定尝试过于频繁，请稍后再试。',
            'stale' => '这条操作已经失效，请重新打开菜单。',
            'unknown' => '请发送 /start 打开菜单。',
            'already_bound' => '你已绑定账号。如需更换，请先在账户信息中解除绑定。',
        ];
        if (isset($notices[$action])) {
            return ['text' => $notices[$action], 'keyboard' => $action === 'private_only' ? [] : $this->back()];
        }
        if ($action === 'unbound') {
            return $binding
                ? ['text' => '该解绑请求已处理，请重新打开当前账号的菜单。', 'keyboard' => $this->back()]
                : ['text' => "已解除绑定。\n如需重新绑定，请发送本面板的订阅链接。"];
        }
        if (!$binding || !$binding->user) {
            return ['text' => "Ciallo～(∠・ω< )⌒☆\n请把你的订阅链接交给我吧！"];
        }
        $user = $binding->user;
        if ($action === 'confirm_reset') {
            if (!$binding->reset_token || ($binding->reset_expires_at ?? 0) <= time()) {
                return ['text' => '重置确认已过期，请重新操作。', 'keyboard' => $this->back()];
            }
            return [
                'text' => "确定重置订阅吗？\n旧订阅链接和节点连接凭据将失效，请用新链接更新客户端订阅。Telegram 绑定会保留。",
                'keyboard' => [[
                    ['text' => '确认重置', 'callback_data' => 'reset:' . $binding->reset_token],
                    ['text' => '取消', 'callback_data' => 'reset_cancel'],
                ]],
            ];
        }
        if ($action === 'confirm_unbind') {
            if (!$binding->unbind_token || ($binding->unbind_expires_at ?? 0) <= time()) {
                return ['text' => '解绑确认已过期，请重新操作。', 'keyboard' => $this->back()];
            }
            return [
                'text' => '确定解除当前账号的 Telegram 绑定吗？',
                'keyboard' => [[
                    ['text' => '确认解绑', 'callback_data' => 'unbind:' . $binding->unbind_token],
                    ['text' => '取消', 'callback_data' => 'unbind_cancel'],
                ]],
            ];
        }
        if (in_array($action, ['subscription', 'link', 'subscription_reset'], true)) {
            $reply = $this->subscription($user);
            if ($action === 'subscription_reset') {
                $reply['text'] = "订阅已重置，Telegram 绑定已保留。\n请用下方新链接更新客户端订阅。\n\n" . $reply['text'];
            }
            return $reply;
        }
        if ($action === 'account') {
            return [
                'text' => implode("\n", [
                    '账户信息',
                    '邮箱：' . $user->email,
                    '当前套餐：' . ($user->plan?->name ?? '未订购套餐'),
                    '账户状态：' . ($user->banned ? '封禁中' : '正常'),
                    '余额：' . number_format($user->balance / 100, 2, '.', '') . ' 元',
                    '注册时间：' . date('Y-m-d H:i', $user->created_at),
                ]),
                'keyboard' => array_merge([[['text' => '解除绑定', 'callback_data' => 'unbind']]], $this->back()),
            ];
        }
        return [
            'text' => $action === 'bound' ? '绑定成功，请选择需要查看的内容。' : 'Ciallo～(∠・ω< )⌒☆ 欢迎回来，今天想看点什么？',
            'keyboard' => [
                [['text' => '订阅信息', 'callback_data' => 'subscription']],
                [['text' => '账户信息', 'callback_data' => 'account']],
            ],
        ];
    }

    private function back(): array
    {
        return [[['text' => '返回主菜单', 'callback_data' => 'menu']]];
    }

    private function subscription(User $user): array
    {
        $percentage = max(0, $user->getTrafficUsagePercentage());
        $filled = (int) round($percentage / 100 * 14);

        return [
            'text' => implode("\n", [
                '订阅信息',
                '套餐：' . ($user->plan?->name ?? '未订购套餐'),
                '状态：' . $this->subscriptionStatus($user),
                '到期时间：' . ($user->expired_at === null ? '长期有效' : date('Y-m-d H:i', $user->expired_at)),
                '已用：' . $this->traffic($user->getTotalUsedTraffic()) . ' / ' . $this->traffic((int) $user->transfer_enable),
                '剩余：' . $this->traffic($user->getRemainingTraffic()),
                str_repeat('█', $filled) . str_repeat('░', 14 - $filled) . ' ' . number_format($percentage, 1, '.', '') . '%',
                $this->trafficResetNotice($user),
                '',
                '订阅链接',
                Helper::getSubscribeUrl($user->token),
            ]),
            'keyboard' => array_merge([[['text' => '重置订阅', 'callback_data' => 'reset_subscription']]], $this->back()),
        ];
    }

    private function traffic(int $bytes): string
    {
        return number_format(max(0, $bytes) / 1073741824, 2, '.', '') . ' GB';
    }

    private function trafficResetNotice(User $user): string
    {
        if ($user->next_reset_at === null) {
            return '不自动重置流量';
        }

        $seconds = $user->next_reset_at - now()->timestamp;
        if ($seconds <= 0) {
            return '已到流量重置时间';
        }

        return '流量重置时间：' . (int) ceil($seconds / 86400) . '天';
    }

    private function subscriptionStatus(User $user): string
    {
        $now = now()->timestamp;

        return match (true) {
            (bool) $user->banned => '封禁中',
            $user->expired_at !== null && $user->expired_at <= $now => '已过期',
            $user->transfer_enable <= 0 => '未开通',
            $user->getRemainingTraffic() <= 0 => '流量耗尽',
            $user->expired_at !== null && $user->expired_at <= $now + 3 * 86400 => '即将到期',
            default => '正常',
        };
    }
}
