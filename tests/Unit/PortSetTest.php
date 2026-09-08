<?php

namespace Tests\Unit;

use App\Utils\PortSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PortSetTest extends TestCase
{
    public function test_merges_duplicates_and_overlapping_ranges_without_expansion(): void
    {
        $this->assertSame([[443, 443], [20000, 20021], [20100, 20100]],
            PortSet::parse('20100,20000-20010,20005-20020,20021,443,443'));
        $this->assertSame([[1, 65535]], PortSet::parse('1-65535'));
        $this->assertSame(['443:443', '20000:20021', '20100:20100'],
            PortSet::singBoxRanges('443,20000-20021,20100'));
        $this->assertSame('443,20000-20021,20100',
            PortSet::normalize(' 00443,20000 - 20010 ,20005-20021,20100 '));
    }

    public function test_random_port_stays_inside_disjoint_ranges(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $port = PortSet::random('443,20000-20010,20300');
            $this->assertTrue($port === 443 || $port === 20300 || ($port >= 20000 && $port <= 20010));
        }
    }

    public function test_rejects_invalid_port_sets(): void
    {
        foreach (['', '443,', ',443', '0', '65536', '200-100', '1-2-3', '1:3', '+443', '1;id', '-1'] as $value) {
            try {
                PortSet::parse($value);
                $this->fail("应拒绝端口表达式 {$value}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
