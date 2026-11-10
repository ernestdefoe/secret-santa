<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int $exchange_id
 * @property int $user_id
 * @property string $wishlist
 * @property string|null $shipping_note
 * @property Carbon|null $joined_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 */
class Participant extends AbstractModel
{
    protected $table = 'secret_santa_participants';

    public $timestamps = false;

    /** Never serialized by accident: Presenter picks what each viewer may read. */
    protected $hidden = ['wishlist', 'shipping_note'];

    protected $casts = [
        'exchange_id' => 'integer',
        'user_id' => 'integer',
        'joined_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
