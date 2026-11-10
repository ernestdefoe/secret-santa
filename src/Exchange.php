<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $description
 * @property string|null $budget
 * @property string $kind
 * @property string $status
 * @property Carbon $signup_ends_at
 * @property Carbon|null $ship_by
 * @property bool $hold_reveal
 * @property bool $unmask
 * @property string|null $draw_error
 * @property int|null $discussion_id
 * @property int $participants_count
 * @property int $shipped_count
 * @property int $received_count
 * @property Carbon|null $drawn_at
 * @property Carbon|null $revealed_at
 * @property Carbon|null $sent_visible_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $organizer
 */
class Exchange extends AbstractModel
{
    /** Sign-ups open. */
    public const OPEN = 'open';
    /** Matches made, nobody told yet: the organizer may re-draw. */
    public const DRAWN = 'drawn';
    /** Every giver knows who they buy for. */
    public const ACTIVE = 'active';
    public const COMPLETE = 'complete';

    public const KINDS = ['physical', 'digital'];

    protected $table = 'secret_santa_exchanges';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'signup_ends_at' => 'datetime',
        'ship_by' => 'date',
        'hold_reveal' => 'boolean',
        'unmask' => 'boolean',
        'discussion_id' => 'integer',
        'participants_count' => 'integer',
        'shipped_count' => 'integer',
        'received_count' => 'integer',
        'drawn_at' => 'datetime',
        'revealed_at' => 'datetime',
        'sent_visible_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function organizer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The creator, or anyone allowed to run every exchange. */
    public function isOrganizer(User $actor): bool
    {
        return ! $actor->isGuest() && ($this->user_id === $actor->id || $actor->can(Settings::MODERATE));
    }

    /**
     * Run $work with this exchange's row locked and read afresh, inside a
     * transaction: anything that deletes or rewires assignments re-checks the
     * status here, so it can't race the draw (a stale "open" would otherwise
     * wipe a live exchange's matches).
     *
     * @template T
     * @param callable(Exchange): T $work
     * @return T|null null when the exchange is gone
     */
    public static function locked(int $id, callable $work): mixed
    {
        return static::query()->getConnection()->transaction(function () use ($id, $work) {
            /** @var Exchange|null $fresh */
            $fresh = static::query()->lockForUpdate()->find($id);

            return $fresh ? $work($fresh) : null;
        });
    }

    /** Organizers see who has sent their gift only from this moment (Draw::sentVisibleAt), whoever they are. */
    public function sentFlagsHidden(): bool
    {
        return $this->status === self::ACTIVE && (! $this->sent_visible_at || $this->sent_visible_at->isFuture());
    }

    public function signupsOpen(): bool
    {
        return $this->status === self::OPEN && $this->signup_ends_at->isFuture();
    }

    /** The counters behind the list page, recounted after anything that moves them. */
    public function recount(): void
    {
        $this->participants_count = Participant::query()->where('exchange_id', $this->id)->count();
        $assignments = Assignment::query()->where('exchange_id', $this->id);
        $this->shipped_count = (clone $assignments)->whereNotNull('shipped_at')->count();
        $this->received_count = (clone $assignments)->whereNotNull('received_at')->count();
        $this->save();
    }
}
