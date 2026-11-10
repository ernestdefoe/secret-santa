<?php

namespace ErnestDefoe\SecretSanta\Api;

use Carbon\Carbon;

/** Reading request bodies: trimmed text within a length, or null. */
final class Input
{
    /** @param array<string, mixed> $body */
    public static function text(array $body, string $key, int $max, int $min = 0): ?string
    {
        $value = $body[$key] ?? null;
        if (! is_string($value)) {
            return $min ? null : '';
        }
        // Windows line endings and stray control characters out; the text is shown as plain text.
        $value = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $value)));

        return mb_strlen($value) < $min || mb_strlen($value) > $max ? null : $value;
    }

    /** @param array<string, mixed> $body */
    public static function moment(array $body, string $key): ?Carbon
    {
        $value = $body[$key] ?? null;
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->startOfMinute();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $body */
    public static function date(array $body, string $key): ?Carbon
    {
        $value = $body[$key] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<int>
     */
    public static function ids(array $body, string $key, int $max): array
    {
        $value = $body[$key] ?? [];
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(array_map('intval', $value), fn (int $id) => $id > 0))), 0, $max);
    }
}
