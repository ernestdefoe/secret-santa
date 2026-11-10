<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Notification\MatchedBlueprint;
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Drawing and revealing matches.
 *
 * At the sign-up deadline an exchange is drawn: by the scheduler
 * (secret-santa:draw) or, on forums without a cron, by the next request that
 * lists or opens an exchange (due()). Drawn matches are secret from
 * everyone; revealing them tells each Santa, and only them, who they buy for.
 * With hold_reveal the organizer looks the draw over first (counts, never
 * pairs) and may re-draw.
 */
class Draw
{
    public function __construct(
        private readonly Matcher $matcher,
        private readonly NotificationSyncer $notifications,
        private readonly ConnectionInterface $db,
    ) {
    }

    /** Exchanges past their deadline that haven't been drawn (or failed to be): draw them now. One indexed query when there are none. */
    public function due(): void
    {
        $due = Exchange::query()
            ->where('status', Exchange::OPEN)
            ->whereNull('draw_error')
            ->where('signup_ends_at', '<=', Carbon::now())
            ->limit(10)
            ->get();

        foreach ($due as $exchange) {
            $this->run($exchange, automatic: true);
        }
    }

    /**
     * Draw (or re-draw, before the reveal). Returns null, or why it couldn't:
     * too_few (under 3 members) or exclusions (no cycle avoids them).
     */
    public function run(Exchange $exchange, bool $automatic = false): ?string
    {
        $error = null;
        $reveal = false;

        $this->db->transaction(function () use ($exchange, $automatic, &$error, &$reveal) {
            /** @var Exchange|null $fresh */
            $fresh = Exchange::query()->lockForUpdate()->find($exchange->id);
            if (! $fresh || ! in_array($fresh->status, [Exchange::OPEN, Exchange::DRAWN], true)
                || ($automatic && ($fresh->status !== Exchange::OPEN || $fresh->draw_error || $fresh->signup_ends_at->isFuture()))) {
                $error = 'state';

                return;
            }

            $members = Participant::query()->where('exchange_id', $fresh->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $exclusions = Exclusion::query()->where('exchange_id', $fresh->id)->get(['user_id', 'other_id'])
                ->map(fn (Exclusion $e) => [$e->user_id, $e->other_id])->all();

            Assignment::query()->where('exchange_id', $fresh->id)->delete();

            $pairs = count($members) < 3 ? null : $this->matcher->cycle($members, $exclusions);
            if ($pairs === null) {
                $error = count($members) < 3 ? 'too_few' : 'exclusions';
                $fresh->status = Exchange::OPEN;
                $fresh->draw_error = $error;
                $fresh->drawn_at = null;
                $fresh->save();

                return;
            }

            $now = Carbon::now();
            Assignment::query()->insert(array_map(
                fn (int $giver, int $receiver) => ['exchange_id' => $fresh->id, 'giver_id' => $giver, 'receiver_id' => $receiver, 'created_at' => $now],
                array_keys($pairs),
                $pairs
            ));

            $fresh->status = Exchange::DRAWN;
            $fresh->draw_error = null;
            $fresh->drawn_at = $now;
            $fresh->save();
            $reveal = ! $fresh->hold_reveal;
        });

        $exchange->refresh();

        if ($error && $error !== 'state' && $automatic && $exchange->organizer) {
            $this->notifications->sync(new NewsBlueprint($exchange, $exchange->organizer, 'draw_failed'), [$exchange->organizer]);
        }
        if ($reveal) {
            $this->reveal($exchange);
        }

        return $error === 'state' ? null : $error;
    }

    /** Tell every Santa who they buy for. Only from DRAWN, once. */
    public function reveal(Exchange $exchange): bool
    {
        $moved = Exchange::query()->whereKey($exchange->id)->where('status', Exchange::DRAWN)
            ->update(['status' => Exchange::ACTIVE, 'revealed_at' => Carbon::now()]);
        if (! $moved) {
            return false;
        }
        $exchange->refresh();

        $assignments = Assignment::query()->where('exchange_id', $exchange->id)->get();
        $users = User::query()->whereIn('id', $assignments->pluck('giver_id')->merge($assignments->pluck('receiver_id'))->unique())->get()->keyBy('id');

        foreach ($assignments as $assignment) {
            $giver = $users->get($assignment->giver_id);
            $receiver = $users->get($assignment->receiver_id);
            if ($giver && $receiver) {
                $this->notifications->sync(new MatchedBlueprint($exchange, $receiver), [$giver]);
            }
        }

        return true;
    }
}
