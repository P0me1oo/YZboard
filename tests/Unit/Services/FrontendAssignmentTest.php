<?php

namespace Tests\Unit\Services;

use App\Services\FrontendAssignment;
use PHPUnit\Framework\TestCase;

class FrontendAssignmentTest extends TestCase
{
    private array $members = [
        ['member_id' => 1, 'weight' => 2, 'addresses' => ['192.0.2.1']],
        ['member_id' => 2, 'weight' => 1, 'addresses' => ['192.0.2.2']],
        ['member_id' => 3, 'weight' => 1, 'addresses' => ['192.0.2.3']],
    ];

    public function test_distribution_follows_weights_and_is_independent_of_list_order(): void
    {
        $counts = [1 => 0, 2 => 0, 3 => 0];
        for ($i = 0; $i < 12000; $i++) {
            $label = substr(hash('sha256', 'assignment-test-' . $i), 0, 32);
            $id = FrontendAssignment::choose($label, $this->members);
            $counts[$id]++;
            $this->assertSame($id, FrontendAssignment::choose($label, array_reverse($this->members)));
        }
        $this->assertEqualsWithDelta(0.5, $counts[1] / 12000, 0.02);
        $this->assertEqualsWithDelta(0.25, $counts[2] / 12000, 0.02);
        $this->assertEqualsWithDelta(0.25, $counts[3] / 12000, 0.02);
    }

    public function test_removal_only_moves_users_of_the_removed_member_and_recovery_restores_assignments(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $label = (string) $i;
            $id = FrontendAssignment::choose($label, $this->members);
            $after = FrontendAssignment::choose($label, array_slice($this->members, 1));
            if ($id !== 1) $this->assertSame($id, $after);
            else $this->assertContains($after, [2, 3]);
            $this->assertSame($id, FrontendAssignment::choose($label, $this->members));
        }
    }

    public function test_no_usable_member_never_returns_a_fallback_address(): void
    {
        $this->assertNull(FrontendAssignment::choose('test', []));
        $this->assertNull(FrontendAssignment::choose('test', [['member_id' => 1, 'weight' => 0, 'addresses' => ['192.0.2.1']]]));
        $this->assertNull(FrontendAssignment::choose('test', [['member_id' => 1, 'weight' => 1, 'addresses' => []]]));
    }
}
