<?php

namespace Tests\Unit\Services;

use App\Services\NodeStateValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodeStateValidatorTest extends TestCase
{
    public function test_valid_nested_state_preserves_values_empty_snapshots_and_optional_fields(): void
    {
        $state = [
            'alive' => [1 => ['8.8.8.8', '2001:4860:4860::8888'], 2 => []],
            'online' => [1 => 0, 2 => '3'], 'connection_counts' => [],
            'relay_user_alive' => [1 => [0 => ['1.1.1.1']]],
            'relay_connection_counts' => [1 => [0 => 0, 2 => 4]],
            'user_speeds' => [1 => [0, 9007199254740991]], 'status' => [], 'metrics' => [],
        ];
        $this->assertSame($state, NodeStateValidator::validate($state + ['unknown' => 'ignored']));
        $this->assertSame([], NodeStateValidator::validate([]));
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_nested_values_are_rejected(array $state): void
    {
        $this->expectException(ValidationException::class);
        NodeStateValidator::validate($state);
    }

    public static function invalidStates(): array
    {
        return [
            'null array' => [['alive' => null]],
            'scalar user devices' => [['alive' => [1 => '8.8.8.8']]],
            'invalid ip' => [['alive' => [1 => ['999.1.1.1']]]],
            'nested ip array' => [['alive' => [1 => [[]]]]],
            'negative count' => [['online' => [1 => -1]]],
            'fractional count' => [['connection_counts' => [1 => 1.5]]],
            'missing relay level' => [['relay_user_alive' => [1 => ['8.8.8.8']]]],
            'bad relay ip' => [['relay_user_alive' => [1 => [0 => ['invalid']]]]],
            'bad relay count' => [['relay_connection_counts' => [1 => [2 => -1]]]],
            'bad speed user' => [['user_speeds' => [0 => [1, 2]]]],
            'speed string' => [['user_speeds' => [1 => ['1', 2]]]],
            'speed overflow' => [['user_speeds' => [1 => [0, 9007199254740992]]]],
            'missing speed' => [['user_speeds' => [1 => [0]]]],
            'invalid status' => [['status' => 'invalid']],
            'invalid metrics' => [['metrics' => false]],
        ];
    }
}
