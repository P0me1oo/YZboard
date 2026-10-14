<?php

namespace Tests\Feature\TelegramBot;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramGroupLinkMigrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        // MariaDB 的截表复用会删除迁移创建的配置行，升级测试需要显式准备它。
        DB::table('v2_telegram_bot_configs')->insertOrIgnore([
            'id' => 1, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_upgrade_copies_plain_or_json_group_link_and_preserves_existing_config(): void
    {
        $migration = require database_path('migrations/2026_10_15_000000_add_telegram_bot_group_link.php');
        foreach ([' https://t.me/+PreviousInvite ', json_encode('https://t.me/+PreviousInvite')] as $value) {
            $migration->down();
            DB::table('v2_telegram_bot_configs')->update(['enabled' => true, 'bot_username' => 'test_bot']);
            DB::table('v2_settings')->updateOrInsert(['name' => 'telegram_discuss_link'], ['value' => $value]);
            $before = (array) DB::table('v2_telegram_bot_configs')->first();
            $migration->up();
            $after = (array) DB::table('v2_telegram_bot_configs')->first();
            $this->assertSame('https://t.me/+PreviousInvite', $after['group_link']);
            unset($after['group_link']);
            $this->assertSame($before, $after);
            $this->assertSame($value, DB::table('v2_settings')->where('name', 'telegram_discuss_link')->value('value'));
        }
    }

    public function test_repeated_upgrade_preserves_edited_or_cleared_link_and_absent_legacy_link_defaults_to_null(): void
    {
        $migration = require database_path('migrations/2026_10_15_000000_add_telegram_bot_group_link.php');
        $migration->down();
        DB::table('v2_settings')->where('name', 'telegram_discuss_link')->delete();
        $migration->up();
        $this->assertNull(DB::table('v2_telegram_bot_configs')->value('group_link'));
        DB::table('v2_settings')->insert(['name' => 'telegram_discuss_link', 'value' => 'https://t.me/old_group']);
        foreach (['https://t.me/new_group', null] as $value) {
            DB::table('v2_telegram_bot_configs')->update(['group_link' => $value]);
            $migration->up();
            $this->assertSame($value, DB::table('v2_telegram_bot_configs')->value('group_link'));
        }
        $migration->down();
        $migration->up();
        $this->assertSame('https://t.me/old_group', DB::table('v2_telegram_bot_configs')->value('group_link'));
    }
}
