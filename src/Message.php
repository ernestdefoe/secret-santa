<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $assignment_id
 * @property bool $from_giver
 * @property string $body
 * @property Carbon|null $created_at
 */
class Message extends AbstractModel
{
    protected $table = 'secret_santa_messages';

    public $timestamps = false;

    protected $hidden = ['body'];

    protected $casts = ['assignment_id' => 'integer', 'from_giver' => 'boolean', 'created_at' => 'datetime'];
}
