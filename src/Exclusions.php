<?php

namespace ErnestDefoe\SecretSanta;

/** The exclusion pairs of an exchange, as [a, b] lists for Matcher. */
final class Exclusions
{
    /** @return list<array{int, int}> */
    public static function pairs(int $exchangeId): array
    {
        return Exclusion::query()->where('exchange_id', $exchangeId)->get(['user_id', 'other_id'])
            ->map(fn (Exclusion $e) => [$e->user_id, $e->other_id])->values()->all();
    }
}
