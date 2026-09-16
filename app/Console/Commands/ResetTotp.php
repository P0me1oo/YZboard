<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * 在验证器丢失时，从服务器命令行关闭指定管理员的两步验证。
 */
class ResetTotp extends Command
{
    protected $signature = 'reset:totp {email}';

    protected $description = '关闭指定账号的两步验证（验证器丢失时使用）';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::byEmail($email)->first();

        if (!$user) {
            $this->error('邮箱不存在');
            return self::FAILURE;
        }

        if ($user->totp_enabled_at === null && empty($user->totp_secret)) {
            $this->info('该账号未启用两步验证，无需处理。');
            return self::SUCCESS;
        }

        $user->totp_secret = null;
        $user->totp_enabled_at = null;
        $user->totp_recovery_codes = null;

        if (!$user->save()) {
            $this->error('关闭失败');
            return self::FAILURE;
        }

        $this->info('两步验证已关闭，请尽快重新绑定。');

        return self::SUCCESS;
    }
}
