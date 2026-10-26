<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;

class AgentLogPolicy
{
    public static function disabled(?ServerMachine $machine = null, ?Server $node = null): bool
    {
        $id = $machine?->id ?? $node?->machine_id;
        return $id !== null && (bool) ServerMachine::whereKey($id)->value('agent_log_disabled');
    }

    public static function view(?ServerMachine $machine = null, ?Server $node = null): array
    {
        return ['version' => 1, 'disabled' => self::disabled($machine, $node)];
    }
}
