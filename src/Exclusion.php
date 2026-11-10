<?php

namespace ErnestDefoe\SecretSanta;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $exchange_id
 * @property int $user_id
 * @property int $other_id
 * @property int|null $created_by
 */
class Exclusion extends AbstractModel
{
    protected $table = 'secret_santa_exclusions';

    public $timestamps = false;

    protected $fillable = ['exchange_id', 'user_id', 'other_id', 'created_by'];

    protected $casts = ['exchange_id' => 'integer', 'user_id' => 'integer', 'other_id' => 'integer', 'created_by' => 'integer'];

    /** A pair, either way round, as one row. */
    public static function pair(int $exchangeId, int $a, int $b, ?int $by): self
    {
        return self::query()->firstOrCreate(
            ['exchange_id' => $exchangeId, 'user_id' => min($a, $b), 'other_id' => max($a, $b)],
            ['created_by' => $by]
        );
    }
}
