<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiteAutoBindingMigrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_migration_defaults_existing_and_new_machines_to_auto_without_changing_bindings_or_traffic(): void
    {
        $migration = require database_path('migrations/2026_11_09_000001_add_lite_auto_bind_to_machines.php');
        $migration->down();
        $machine = ServerMachine::create(['name' => '已有机器', 'token' => Str::random(32), 'is_active' => true,
            'traffic_limit' => 1000, 'traffic_state' => ['tx' => 17]]);
        $uuid = (string) Str::uuid();
        DB::table('v2_lite_machine')->insert(['machine_id' => $machine->id, 'uuid' => $uuid]);
        $before = $machine->fresh()->getAttributes();
        $migration->up();
        $this->assertTrue($machine->fresh()->lite_auto_bind);
        $new = ServerMachine::create(['name' => '新机器', 'token' => Str::random(32)]);
        $this->assertTrue($new->fresh()->lite_auto_bind);
        $migration->down();
        $this->assertSame($before, $machine->fresh()->getAttributes());
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $machine->id, 'uuid' => $uuid]);
        $migration->up();
    }
}
