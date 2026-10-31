<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Crypt;

class AgentPrivacy
{
    /** 文件密钥与机器身份绑定，不把面板主密钥交给节点。 */
    public static function view(?ServerMachine $machine = null, ?Server $node = null, bool $includeKeys = true): array
    {
        $machine ??= $node?->machine_id ? ServerMachine::find($node->machine_id) : null;
        $result = ['version' => 1, 'enabled' => AgentLogPolicy::disabled($machine), 'keys' => []];
        if (!$result['enabled'] || !$includeKeys) {
            return $result;
        }

        foreach (Crypt::getAllKeys() as $rootKey) {
            $key = hash_hkdf('sha256', $rootKey, 32,
                'YZ-Agent/private-storage/v1/machine/' . $machine->id);
            $result['keys'][] = ['id' => substr(hash('sha256', $key), 0, 32), 'key' => base64_encode($key)];
        }

        return $result;
    }
}
