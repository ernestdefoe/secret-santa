<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $exchange_id
 * @property int $giver_id
 * @property int $receiver_id
 * @property Carbon|null $shipped_at
 * @property string|null $gift_note
 * @property Carbon|null $received_at
 * @property string|null $thanks
 * @property Carbon|null $created_at
 */
class Assignment extends AbstractModel
{
    protected $table = 'secret_santa_assignments';

    public $timestamps = false;

    /** The whole row is a secret: nothing of it is ever serialized as is. */
    protected $hidden = ['giver_id', 'receiver_id', 'gift_note', 'thanks'];

    protected $casts = [
        'exchange_id' => 'integer',
        'giver_id' => 'integer',
        'receiver_id' => 'integer',
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
