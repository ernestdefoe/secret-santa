<?php

namespace ErnestDefoe\SecretSanta\Tests\integration\api;

use ErnestDefoe\SecretSanta\Matcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MatcherTest extends TestCase
{
    #[Test]
    public function it_needs_three_people()
    {
        $this->assertNull((new Matcher())->cycle([1, 2]));
        $this->assertNotNull((new Matcher())->cycle([1, 2, 3]));
    }

    #[Test]
    public function every_draw_is_one_cycle_with_no_one_drawing_themselves()
    {
        $members = range(1, 40);
        for ($i = 0; $i < 50; $i++) {
            $pairs = (new Matcher())->cycle($members, [[1, 2], [3, 4], [5, 6]]);
            $at = 1;
            $steps = 0;
            do {
                $this->assertNotSame($at, $pairs[$at]);
                $at = $pairs[$at];
                $steps++;
            } while ($at !== 1);
            $this->assertSame(40, $steps);
            foreach ([[1, 2], [3, 4], [5, 6]] as [$a, $b]) {
                $this->assertNotSame($b, $pairs[$a]);
                $this->assertNotSame($a, $pairs[$b]);
            }
        }
    }

    #[Test]
    public function dense_exclusions_still_find_the_one_way_round()
    {
        // 6 people where only the order 1-2-3-4-5-6 (or its reverse) works: everything else is excluded.
        $members = range(1, 6);
        $allowed = [[1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 1]];
        $exclusions = [];
        foreach ($members as $a) {
            foreach ($members as $b) {
                if ($a < $b && ! in_array([$a, $b], $allowed, true) && ! in_array([$b, $a], $allowed, true)) {
                    $exclusions[] = [$a, $b];
                }
            }
        }
        $pairs = (new Matcher())->cycle($members, $exclusions);
        $this->assertNotNull($pairs);
        foreach ($pairs as $giver => $receiver) {
            $this->assertTrue(in_array([min($giver, $receiver), max($giver, $receiver)], array_map(fn ($p) => [min($p), max($p)], $allowed), true));
        }

        // Cut one more edge and there is no way round.
        $exclusions[] = [1, 2];
        $this->assertNull((new Matcher())->cycle($members, $exclusions));
    }
}
