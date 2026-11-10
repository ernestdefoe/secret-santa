<?php

namespace ErnestDefoe\SecretSanta\Tests\integration\api;

use ErnestDefoe\SecretSanta\Matcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MatcherTest extends TestCase
{
    #[Test]
    public function it_needs_four_people()
    {
        // With three, A buys for B, so C is A's Santa.
        $this->assertNull((new Matcher())->cycle([1, 2, 3]));
        $this->assertNotNull((new Matcher())->cycle([1, 2, 3, 4]));
    }

    #[Test]
    public function nobody_may_be_left_with_fewer_than_three_possible_neighbours()
    {
        $this->assertSame([], Matcher::cornered([1, 2, 3, 4, 5], [[1, 2]]));
        $this->assertSame([1], Matcher::cornered([1, 2, 3, 4, 5], [[1, 2], [1, 3]]));
        $this->assertNull((new Matcher())->cycle([1, 2, 3, 4, 5], [[1, 2], [1, 3]]));
    }

    #[Test]
    public function running_out_of_steps_is_not_called_impossible()
    {
        // 9 people in two groups that may not mix (each still with 3 allowed): no cycle exists.
        $members = range(1, 9);
        $exclusions = [];
        foreach ([1, 2, 3, 4] as $a) {
            foreach ([5, 6, 7, 8, 9] as $b) {
                $exclusions[] = [$a, $b];
            }
        }
        $quick = new Matcher(new \Random\Randomizer(), 5);
        $this->assertNull($quick->cycle($members, $exclusions));
        $this->assertTrue($quick->exhausted, 'gave up on the budget');

        $thorough = new Matcher();
        $this->assertNull($thorough->cycle($members, $exclusions));
        $this->assertFalse($thorough->exhausted, 'searched it all: no way round');
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
    public function dense_exclusions_still_find_the_way_round()
    {
        // 8 people, each allowed exactly 3 neighbours (a ring plus its opposite): the backtracking finds a cycle.
        $members = range(1, 8);
        $allowed = [[1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 8], [8, 1], [1, 5], [2, 6], [3, 7], [4, 8]];
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

        // Cut one more and member 1 is cornered (only 2 left): refused before searching.
        $exclusions[] = [1, 2];
        $this->assertNull((new Matcher())->cycle($members, $exclusions));
        $this->assertFalse((new Matcher())->exhausted);
    }
}
