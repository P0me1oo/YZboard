<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCouponUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create([
            'email' => 'coupon-admin@example.test',
            'password' => Str::random(32),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'is_admin' => true,
        ]));
    }

    private function updateCoupon(array $values)
    {
        return $this->postJson('/api/v2/' . hash('crc32b', config('app.key')) . '/coupon/update', $values);
    }

    public function test_success_returns_confirmation_and_repeated_switches_persist(): void
    {
        $coupon = Coupon::create([
            'name' => '测试优惠券', 'code' => Str::random(12), 'type' => 1, 'value' => 100,
            'started_at' => now()->timestamp, 'ended_at' => now()->addDay()->timestamp, 'show' => true,
        ]);
        foreach ([false, false, true] as $visible) {
            $this->updateCoupon(['id' => $coupon->id, 'show' => $visible])->assertOk()->assertJsonPath('data', true);
            $this->assertSame($visible, $coupon->fresh()->show);
        }
    }

    public function test_failure_preserves_transaction_level_and_next_request_can_succeed(): void
    {
        $level = DB::transactionLevel();
        $this->updateCoupon(['id' => 2147483647, 'show' => false])->assertStatus(500);
        $this->assertSame($level, DB::transactionLevel());
        $this->test_success_returns_confirmation_and_repeated_switches_persist();
    }
}
