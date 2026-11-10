<?php

namespace ErnestDefoe\SecretSanta;

use Random\Randomizer;

/**
 * The draw: one cycle through every member (A buys for B, B for C, ... back
 * to A), so nobody draws themselves and no two people simply swap. Pairs
 * that must not meet (a couple) may sit next to each other in neither
 * direction.
 *
 * 🚨 Fewer than 4 members, or a member with fewer than 3 allowed neighbours,
 * and a member can work their Santa out (with 3, A buys for B so C is A's
 * Santa). Those draws are refused: see MIN_MEMBERS / MIN_NEIGHBOURS.
 *
 * Random orders almost always work; when exclusions are dense, a randomized
 * backtracking search finds a cycle if one exists (within a step budget).
 * The randomness is the CSPRNG: the draw must not be guessable.
 */
final class Matcher
{
    public const MIN_MEMBERS = 4;
    public const MIN_NEIGHBOURS = 3;

    private const SHUFFLES = 300;

    /** True when the last cycle() gave up on its step budget rather than proving there is no way round. */
    public bool $exhausted = false;

    public function __construct(private readonly Randomizer $random = new Randomizer(), private readonly int $steps = 1000000)
    {
    }

    /**
     * Members left with fewer than MIN_NEIGHBOURS people they may sit beside.
     *
     * @param list<int> $members
     * @param list<array{int, int}> $exclusions
     * @return list<int>
     */
    public static function cornered(array $members, array $exclusions): array
    {
        $members = array_values(array_unique($members));
        $in = array_flip($members);
        $excluded = [];
        foreach ($exclusions as [$a, $b]) {
            if (isset($in[$a], $in[$b]) && $a !== $b) {
                $excluded[$a][$b] = $excluded[$b][$a] = true;
            }
        }

        return array_values(array_filter($members, fn (int $m) => count($members) - 1 - count($excluded[$m] ?? []) < self::MIN_NEIGHBOURS));
    }

    /**
     * @param list<int> $members
     * @param list<array{int, int}> $exclusions
     * @return array<int, int>|null giver => receiver, or null: fewer than MIN_MEMBERS, a member
     *                              cornered by exclusions, no cycle, or the step budget ran out ($exhausted)
     */
    public function cycle(array $members, array $exclusions = []): ?array
    {
        $this->exhausted = false;
        $members = array_values(array_unique($members));
        $n = count($members);
        if ($n < self::MIN_MEMBERS || self::cornered($members, $exclusions)) {
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

        if ($this->extend($path, $used, $members, $blocked, $steps, $start)) {
            return self::pairs($path);
        }
        $this->exhausted = $steps > $this->steps;

        return null;
    }

    /**
     * @param list<int> $path
     * @param array<int, bool> $used
     * @param list<int> $members
     * @param array<int, array<int, bool>> $blocked
     */
    private function extend(array &$path, array &$used, array $members, array $blocked, int &$steps, int $start): bool
    {
        if (++$steps > $this->steps) {
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
