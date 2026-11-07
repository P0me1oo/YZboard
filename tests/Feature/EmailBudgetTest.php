<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EmailBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Bus::fake();
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0]);
        config(['security.email_verify.per_ip_hour' => 3, 'security.email_verify.per_hour' => 5]);
    }

    private function send(string $ip, int $id)
    {
        return $this->withHeader('X-Forwarded-For', $ip)->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => "budget{$id}@example.com"]);
    }

    public function test_changing_email_does_not_reset_ip_budget_and_other_sources_remain_independent(): void
    {
        for ($i = 0; $i < 3; $i++) $this->send('203.0.113.61', $i)->assertOk();
        $this->send('203.0.113.61', 3)->assertStatus(429);
        $this->send('203.0.113.62', 4)->assertOk();
        Bus::assertDispatchedTimes(SendEmailJob::class, 4);
    }

    public function test_global_budget_is_shared_across_ips_and_api_versions_then_expires(): void
    {
        for ($i = 1; $i <= 5; $i++) $this->send("203.0.113.{$i}", $i)->assertOk();
        $this->withHeader('X-Forwarded-For', '203.0.113.100')->postJson('/api/v2/passport/comm/sendEmailVerify', ['email' => 'six@example.com'])->assertStatus(429);
        Bus::assertDispatchedTimes(SendEmailJob::class, 5);
        $this->travel(3601)->seconds();
        $this->send('203.0.113.101', 8)->assertOk();
    }
}
