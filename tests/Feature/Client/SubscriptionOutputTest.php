<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('v2board', []);
        Route::middleware('admin')->group(function (): void {
            Route::get('/_tests/subscription-output/config', [ConfigController::class, 'fetch']);
            Route::post('/_tests/subscription-output/config', [ConfigController::class, 'save']);
        });
        SubscribeTemplate::setContent('clashmeta', "proxies: []\nproxy-groups:\n  - name: SELECT\n    type: select\n    proxies: []\nrules:\n  - MATCH,DIRECT\n");
        SubscribeTemplate::setContent('singbox', '{"route":{"rules":[]},"outbounds": [{"type":"selector","tag":"SELECT","outbounds":[]}]}');
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.invalid', 'password' => Str::random(24),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin,
            'group_id' => 1, 'transfer_enable' => 102400, 'expired_at' => null, 'u' => 0, 'd' => 0]);
    }

    private function node(array $values = []): Server
    {
        return Server::create($values + ['name' => '香港 01', 'type' => 'vless', 'host' => '10.20.30.40',
            'port' => 443, 'server_port' => 443, 'kernel_type' => 'xray', 'rate' => 0.8,
            'group_ids' => ['1'], 'show' => true, 'enabled' => true,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 0]]);
    }

    private function subscription(User $user, string $format, ?array $servers = null, array $query = []): string
    {
        return app(ClientController::class)->doSubscribe(Request::create('/', 'GET', ['flag' => $format] + $query), $user, $servers)->getContent();
    }

    public function test_defaults_save_reload_validation_and_independent_switches(): void
    {
        $this->actingAs($this->user(true), 'sanctum');
        $this->getJson('/_tests/subscription-output/config?key=subscribe')->assertOk()
            ->assertJsonPath('data.subscribe.show_flag_to_server_enable', false)
            ->assertJsonPath('data.subscribe.show_rate_to_server_enable', false);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/_tests/subscription-output/config', ['show_flag_to_server_enable' => true,
                'show_rate_to_server_enable' => true])->assertOk()->assertJsonPath('data', true);
        }
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/subscription-output/config?key=subscribe')->assertOk()
            ->assertJsonPath('data.subscribe.show_flag_to_server_enable', true)
            ->assertJsonPath('data.subscribe.show_rate_to_server_enable', true);
        $this->postJson('/_tests/subscription-output/config', ['show_rate_to_server_enable' => false])->assertOk();
        $this->assertTrue((bool) admin_setting('show_flag_to_server_enable'));
        foreach (['bad', [], null, 2] as $invalid) {
            $this->postJson('/_tests/subscription-output/config', ['show_flag_to_server_enable' => $invalid])->assertUnprocessable();
            $this->assertTrue((bool) admin_setting('show_flag_to_server_enable'));
        }
        $this->postJson('/_tests/subscription-output/config', ['admin_sidebar_hidden' => ['subscription-settings', 'subscription-template']])->assertOk();
    }

    public function test_names_reach_uri_yaml_json_and_selector_references_without_changing_stored_names(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->assertStringContainsString('#' . rawurlencode('香港 01'), base64_decode($this->subscription($user, 'general')));
        admin_setting(['show_flag_to_server_enable' => true, 'show_rate_to_server_enable' => true, 'show_protocol_to_server_enable' => true]);
        $name = '🇭🇰 [vless]香港 01｜0.8×';
        for ($i = 0; $i < 2; $i++) {
            $this->assertStringContainsString('#' . rawurlencode($name), base64_decode($this->subscription($user, 'general')));
            $yaml = Yaml::parse($this->subscription($user, 'clashmeta'));
            $this->assertSame($name, $yaml['proxies'][0]['name']);
            $this->assertContains($name, $yaml['proxy-groups'][0]['proxies']);
            $json = json_decode($this->subscription($user, 'sing-box'), true);
            $this->assertContains($name, array_column($json['outbounds'], 'tag'));
            $this->assertContains($name, $json['outbounds'][0]['outbounds']);
        }
        $this->assertSame('香港 01', $node->fresh()->name);
        $node->update(['name' => '🇭🇰 香港 01']);
        $this->assertStringContainsString('#' . rawurlencode($name), base64_decode($this->subscription($user, 'general')));
        $node->update(['name' => '香港 01']);
        admin_setting(['show_rate_to_server_enable' => false, 'show_protocol_to_server_enable' => false]);
        $this->assertStringContainsString('#' . rawurlencode('🇭🇰 香港 01'), base64_decode($this->subscription($user, 'general')));
    }

    public function test_scheduled_and_relay_effective_rates_and_one_times_omission(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $user = $this->user();
        $entry = $this->node(['rate' => 3, 'rate_time_enable' => true,
            'rate_time_ranges' => [['start' => '00:00', 'end' => '23:59', 'rate' => 0.8]]]);
        $child = $this->node(['name' => '日本落地', 'relay_entry_id' => $entry->id, 'rate' => 0.25]);
        admin_setting(['show_rate_to_server_enable' => true]);
        $this->assertStringContainsString(rawurlencode('日本落地｜0.8×'), base64_decode($this->subscription($user, 'general')));
        $child->update(['relay_rate_override' => true]);
        $this->assertStringContainsString(rawurlencode('日本落地｜0.25×'), base64_decode($this->subscription($user, 'general')));
        $child->update(['rate' => 1]);
        $this->assertStringContainsString('#' . rawurlencode('日本落地') . "\r\n", base64_decode($this->subscription($user, 'general')));
        $entry->update(['rate_time_ranges' => []]);
        $this->assertStringContainsString(rawurlencode('香港 01｜3×'), base64_decode($this->subscription($user, 'general')));
    }

    public function test_information_rows_and_filters_do_not_receive_node_decorations(): void
    {
        $user = $this->user();
        $this->node();
        $this->node(['name' => '日本 02']);
        admin_setting(['show_flag_to_server_enable' => true, 'show_rate_to_server_enable' => true,
            'show_protocol_to_server_enable' => true, 'show_info_to_server_enable' => true]);
        $servers = ServerService::getAvailableServers($user);
        $yaml = Yaml::parse($this->subscription($user, 'clashmeta', $servers, ['filter' => '香港']));
        $names = array_column($yaml['proxies'], 'name');
        $this->assertContains('过滤掉1条线路', $names);
        $this->assertContains('套餐到期：长期有效', $names);
        $this->assertContains('🇭🇰 [vless]香港 01｜0.8×', $names);
        foreach (array_slice($names, 0, -1) as $name) {
            $this->assertDoesNotMatchRegularExpression('/[\x{1F1E6}-\x{1F1FF}]|×|\[vless\]/u', $name);
        }
        $this->assertSame([], Yaml::parse($this->subscription($user, 'clashmeta', []))['proxies']);
    }
}
