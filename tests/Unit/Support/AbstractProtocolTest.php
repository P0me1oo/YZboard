<?php

namespace Tests\Unit\Support;

use App\Support\AbstractProtocol;
use PHPUnit\Framework\TestCase;

class AbstractProtocolTest extends TestCase
{
    public function test_subscription_userinfo_rounds_decimal_bytes_without_changing_source_data(): void
    {
        $user = [
            'u' => '84331184.85',
            'd' => '3099961804.49',
            'transfer_enable' => 10737418240,
            'expired_at' => null,
        ];
        $protocol = new SubscriptionUserInfoTestProtocol($user);

        $this->assertSame(
            'upload=84331185; download=3099961804; total=10737418240; expire=0',
            $protocol->subscriptionUserInfo()
        );
        $this->assertSame($user, $protocol->sourceUser());
    }

    public function test_subscription_userinfo_preserves_integer_values(): void
    {
        $protocol = new SubscriptionUserInfoTestProtocol([
            'u' => 1024,
            'd' => 2048,
            'transfer_enable' => 4096,
            'expired_at' => 1777777777,
        ]);

        $this->assertSame(
            'upload=1024; download=2048; total=4096; expire=1777777777',
            $protocol->subscriptionUserInfo()
        );
    }
}

class SubscriptionUserInfoTestProtocol extends AbstractProtocol
{
    public function __construct(array $user)
    {
        $this->user = $user;
        $this->servers = [];
    }

    public function handle(): void
    {
    }

    public function subscriptionUserInfo(): string
    {
        return $this->buildSubscriptionUserInfo();
    }

    public function sourceUser(): array
    {
        return $this->user;
    }
}
