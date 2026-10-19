<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiline_urls_save_reload_and_user_response_use_the_first_address(): void
    {
        Route::post('/_tests/subscription-url/config', [ConfigController::class, 'save']);
        Route::get('/_tests/subscription-url/config', [ConfigController::class, 'fetch']);
        $user = User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(24),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]);
        $this->actingAs($user, 'sanctum');
        $urls = "\r\n https://first.example.test/base/ \r\n\r\n https://second.example.test \n";
        $this->postJson('/_tests/subscription-url/config', ['subscribe_url' => $urls])
            ->assertOk()->assertJsonPath('data', true);
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/subscription-url/config?key=site')->assertOk()
            ->assertJsonPath('data.site.subscribe_url', trim($urls));
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/user/getSubscribe')->assertOk()
                ->assertJsonPath('data.subscribe_url', 'https://first.example.test/base/s/' . $user->token);
        }
    }

    public function test_legacy_and_mixed_separators_keep_the_first_nonempty_address(): void
    {
        $token = Str::random(32);
        foreach ([
            'https://first.example.test/,https://second.example.test',
            "\n, \r\n https://first.example.test/ , https://second.example.test\rhttps://third.example.test ",
        ] as $urls) {
            app(Setting::class)->set('subscribe_url', $urls);
            $this->assertSame('https://first.example.test/', Helper::getSubscribeUrls()[0]);
            for ($i = 0; $i < 8; $i++) {
                $this->assertSame('https://first.example.test/s/' . $token, Helper::getSubscribeUrl($token));
            }
        }
    }

    public function test_blank_configuration_uses_the_current_site_url(): void
    {
        $token = Str::random(32);
        foreach ([null, '', " \r\n , \t\n"] as $urls) {
            app(Setting::class)->set('subscribe_url', $urls);
            $this->assertSame([], Helper::getSubscribeUrls());
            $this->assertSame(url('/s/' . $token), Helper::getSubscribeUrl($token));
        }
    }

    public function test_first_address_preserves_dynamic_patterns_and_custom_path(): void
    {
        $token = Str::random(32);
        app(Setting::class)->save(['subscribe_url' => "https://[1-3].example.test/base/\nhttps://second.example.test"]);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add((new \Illuminate\Routing\Route('GET', 'custom-sub/{token}', fn () => null))->name('client.subscribe'));
        app('url')->setRoutes($routes);
        $this->assertMatchesRegularExpression('~\Ahttps://[1-3]\.example\.test/base/custom-sub/' . $token . '\z~',
            Helper::getSubscribeUrl($token));
        app(Setting::class)->set('subscribe_url', "https://[uuid].example.test\nhttps://second.example.test");
        $this->assertMatchesRegularExpression('~\Ahttps://[a-f0-9-]+\.example\.test/custom-sub/' . $token . '\z~',
            Helper::getSubscribeUrl($token));
    }

    public function test_explicit_address_and_plugin_filter_remain_supported(): void
    {
        $token = Str::random(32);
        app(Setting::class)->set('subscribe_url', "https://first.example.test\nhttps://second.example.test");
        $this->assertSame('https://explicit.example.test/base/s/' . $token,
            Helper::getSubscribeUrl($token, 'https://explicit.example.test/base/'));
        HookManager::registerFilter('subscribe.url', fn ($url) => $url . '?flag=clash');
        $this->assertSame('https://first.example.test/s/' . $token . '?flag=clash', Helper::getSubscribeUrl($token));
    }
}
