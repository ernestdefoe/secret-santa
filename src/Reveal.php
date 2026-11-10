<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $exchange_id
 * @property int|null $actor_id
 * @property int|null $user_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class Reveal extends AbstractModel
{
    protected $table = 'secret_santa_reveals';

    public $timestamps = false;

    protected $casts = ['exchange_id' => 'integer', 'actor_id' => 'integer', 'user_id' => 'integer', 'created_at' => 'datetime'];
}
