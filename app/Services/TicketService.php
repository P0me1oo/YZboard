<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    public function closeTicket(Ticket $ticket, User $actor, ?string $settlement = null): void
    {
        DB::transaction(function () use ($ticket, $actor, $settlement) {
            $user = User::whereKey($ticket->user_id)->lockForUpdate()->firstOrFail();
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $admin = $actor->is_admin;
            if (!$admin && (int) $actor->id !== (int) $ticket->user_id) throw new ApiException('无权关闭该工单', 403);
            if ((int) $ticket->status === Ticket::STATUS_CLOSED) return;
            if ($ticket->withdraw_status === 'pending') {
                if (!$admin) throw new ApiException('提现工单由管理员确认处理结果', 422);
                if (!in_array($settlement, ['paid', 'cancelled'], true)) throw new ApiException('请确认提现已付款或退回', 422);
                if ($settlement === 'cancelled') {
                    $user->commission_balance += $ticket->withdraw_amount;
                    if (!$user->save()) throw new \RuntimeException('退回佣金失败');
                }
                $ticket->withdraw_status = $settlement;
            }
            $ticket->status = Ticket::STATUS_CLOSED;
            if (!$ticket->save()) throw new \RuntimeException('关闭工单失败');
        }, 3);
    }

    public function reply($ticket, $message, $userId)
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            $isAdmin = $userId !== $ticket->user_id;
            $ticket->reply_status = $isAdmin
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_WAITING;
            $ticket->last_reply_user_id = $userId;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            app(\App\Services\TelegramBot\BusinessNotificationService::class)->recordTicket($ticketMessage);
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            FailureLog::record('工单回复事务失败', $e, ['ticket_id' => (int) $ticket->id, 'user_id' => (int) $userId]);
            DB::rollback();
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        $ticket = Ticket::where('id', $ticketId)->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticketMessage = $this->reply($ticket, $message, $userId);
        if (!$ticketMessage) {
            throw new ApiException('工单回复失败');
        }
        $this->notifyAdminReply($ticket, $ticketMessage);
    }

    public function notifyAdminReply(Ticket $ticket, TicketMessage $ticketMessage): void
    {
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        return DB::transaction(function () use ($userId, $subject, $level, $message) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level,
                'reply_status' => Ticket::REPLY_STATUS_WAITING,
                'last_reply_user_id' => $userId,
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                throw new ApiException('工单消息创建失败');
            }
            app(\App\Services\TelegramBot\BusinessNotificationService::class)->recordTicket($ticketMessage);
            return $ticket;
        }, 3);
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'content_mode' => 'text',
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
