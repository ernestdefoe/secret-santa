<?php

namespace ErnestDefoe\SecretSanta;

use Random\Randomizer;

/**
 * The draw: one cycle through every member (A buys for B, B for C, ... back
 * to A), so nobody draws themselves and no two people simply swap. Pairs
 * that must not meet (a couple) may sit next to each other in neither
 * direction.
 *
 * Random orders almost always work; when exclusions are dense, a randomized
 * backtracking search finds a cycle if one exists (within a step budget).
 * The randomness is the CSPRNG: the draw must not be guessable.
 */
final class Matcher
{
    private const SHUFFLES = 300;
    private const STEPS = 200000;

    public function __construct(private readonly Randomizer $random = new Randomizer())
    {
    }

    /**
     * @param list<int> $members
     * @param list<array{int, int}> $exclusions
     * @return array<int, int>|null giver => receiver, or null if no cycle exists (or fewer than 3 members)
     */
    public function cycle(array $members, array $exclusions = []): ?array
    {
        $members = array_values(array_unique($members));
        $n = count($members);
        if ($n < 3) {
            return null;
        }

        $blocked = [];
        foreach ($exclusions as [$a, $b]) {
            $blocked[$a][$b] = $blocked[$b][$a] = true;
        }

        for ($i = 0; $i < self::SHUFFLES; $i++) {
            $order = $this->random->shuffleArray($members);
            $ok = true;
            for ($j = 0; $j < $n && $ok; $j++) {
                $ok = ! isset($blocked[$order[$j]][$order[($j + 1) % $n]]);
            }
            if ($ok) {
                return self::pairs($order);
            }
        }

        $steps = 0;
        $start = $members[0];
        $path = [$start];
        $used = [$start => true];

        return $this->extend($path, $used, $members, $blocked, $steps, $start) ? self::pairs($path) : null;
    }

    /**
     * @param list<int> $path
     * @param array<int, bool> $used
     * @param list<int> $members
     * @param array<int, array<int, bool>> $blocked
     */
    private function extend(array &$path, array &$used, array $members, array $blocked, int &$steps, int $start): bool
    {
        if (++$steps > self::STEPS) {
            return false;
        }
        $last = $path[count($path) - 1];
        if (count($path) === count($members)) {
            return ! isset($blocked[$last][$start]);
        }

        foreach ($this->random->shuffleArray($members) as $next) {
            if (isset($used[$next]) || isset($blocked[$last][$next])) {
                continue;
            }
            $path[] = $next;
            $used[$next] = true;
            if ($this->extend($path, $used, $members, $blocked, $steps, $start)) {
                return true;
            }
            array_pop($path);
            unset($used[$next]);
        }

        return false;
    }

    /**
     * @param list<int> $order
     * @return array<int, int>
     */
    private static function pairs(array $order): array
    {
        $pairs = [];
        foreach ($order as $i => $giver) {
            $pairs[$giver] = $order[($i + 1) % count($order)];
        }

        return $pairs;
    }
}
