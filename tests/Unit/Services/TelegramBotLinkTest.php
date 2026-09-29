<?php

namespace Tests\Unit\Services;

use App\Models\TelegramBotBinding;
use App\Models\User;
use App\Services\TelegramBot\BindingException;
use App\Services\TelegramBot\SubscriptionLinkResolver;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramBotLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_resolver_supports_current_and_legacy_routes_without_http(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        app(Setting::class)->save(['app_url' => 'https://panel.example.test', 'subscribe_url' => 'https://sub.example.test/base']);
        $token = Str::random(32);
        $resolver = app(SubscriptionLinkResolver::class);
        foreach ([
            'https://panel.example.test/s/' . $token,
            'https://sub.example.test/base/s/' . $token,
            'https://panel.example.test/api/v1/client/subscribe?token=' . $token . '&flag=clash',
            'https://panel.example.test:443/s/' . $token,
        ] as $url) {
            $this->assertSame($token, $resolver->token($url));
        }
        app(Setting::class)->set('subscribe_path', 'subscription');
        $this->assertSame($token, $resolver->token('https://panel.example.test/subscription/' . $token));
        Http::assertNothingSent();
    }

    public function test_untrusted_ambiguous_or_non_subscription_links_are_rejected(): void
    {
        app(Setting::class)->save(['app_url' => 'https://panel.example.test', 'subscribe_url' => '']);
        $token = Str::random(32);
        $base = 'https://panel.example.test';
        $invalid = [
            'https://elsewhere.example.test/s/' . $token,
            'https://panel.example.test.elsewhere.test/s/' . $token,
            'https://user:password@panel.example.test/s/' . $token,
            'https://panel.example.test:444/s/' . $token,
            'http://panel.example.test/s/' . $token,
            $base . '/wrong/' . $token,
            $base . '/s/' . $token . '/extra',
            $base . '/s/' . $token . '#fragment',
            $base . '/s/' . $token . '?token=another',
            $base . '/api/v1/client/subscribe?token[]=value',
            $base . '/api/v1/client/subscribe?token=first&token=' . $token,
            $base . '/api/v1/client/subscribe?token[]=first&token=' . $token,
            $base . '/wrong?token=' . $token,
            '/s/' . $token,
            $token,
            'file:///s/' . $token,
            $base . "/s/\0" . $token,
        ];
        foreach ($invalid as $url) {
            try {
                app(SubscriptionLinkResolver::class)->token($url);
                $this->fail('无效链接不应被接受。');
            } catch (BindingException $e) {
                $this->assertSame('invalid_link', $e->getMessage());
            }
        }
    }

    public function test_configured_dynamic_domains_are_matched_with_range_limits(): void
    {
        app(Setting::class)->save([
            'app_url' => 'https://panel.example.test',
            'subscribe_url' => 'https://[1-3].sub.example.test,https://[uuid].dynamic.example.test/base',
        ]);
        $token = Str::random(32);
        $resolver = app(SubscriptionLinkResolver::class);
        $this->assertSame($token, $resolver->token('https://2.sub.example.test/s/' . $token));
        $this->assertSame($token, $resolver->token('https://' . Helper::guid(true) . '.dynamic.example.test/base/s/' . $token));
        $this->expectException(BindingException::class);
        $resolver->token('https://4.sub.example.test/s/' . $token);
    }

    public function test_database_enforces_uniqueness_independently_of_the_binding_service(): void
    {
        $users = [];
        for ($i = 0; $i < 2; $i++) {
            $users[] = User::create([
                'email' => Str::random(12) . '@example.test',
                'password' => password_hash(Str::random(24), PASSWORD_DEFAULT),
                'token' => Helper::guid(false), 'uuid' => Helper::guid(true),
            ]);
        }
        TelegramBotBinding::create(['user_id' => $users[0]->id, 'telegram_id' => 900000001]);
        foreach ([
            ['user_id' => $users[0]->id, 'telegram_id' => 900000002],
            ['user_id' => $users[1]->id, 'telegram_id' => 900000001],
        ] as $duplicate) {
            try {
                DB::transaction(fn () => TelegramBotBinding::create($duplicate));
                $this->fail('数据库必须拒绝重复绑定。');
            } catch (UniqueConstraintViolationException) {
                $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
            }
        }
    }
}
