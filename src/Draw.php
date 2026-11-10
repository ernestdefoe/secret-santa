<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use ErnestDefoe\SecretSanta\Job\SendNews;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Drawing and revealing matches.
 *
 * At the sign-up deadline an exchange is drawn: by the scheduler
 * (secret-santa:draw) or, on forums without a cron, by the next request that
 * lists or opens an exchange (due()). Drawn matches are secret from
 * everyone; revealing them tells each Santa, and only them, who they buy for.
 * With hold_reveal the organizer looks the draw over first (counts, never
 * pairs) and may re-draw. The alerts themselves go out on the queue (SendNews).
 */
class Draw
{
    public function __construct(
        private readonly Matcher $matcher,
        private readonly NotificationSyncer $notifications,
        private readonly ConnectionInterface $db,
        private readonly Queue $queue,
    ) {
    }

    /**
     * When organizers may see who has sent: the send-by date, but never less
     * than two weeks after matches go out, and three weeks with no date.
     * Fixed at the reveal (see sent_visible_at).
     */
    public static function sentVisibleAt(?Carbon $shipBy, Carbon $revealedAt): Carbon
    {
        $floor = $revealedAt->copy()->addWeeks(2);
        $at = $shipBy ? $shipBy->copy()->endOfDay() : $revealedAt->copy()->addWeeks(3);

        return $at->max($floor);
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
     * too_few (under 4 members), exclusions (someone has under 3 people they may
     * be matched with, or no cycle avoids them) or busy (no cycle found within
     * the search budget: drawing again may work).
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
            $exclusions = Exclusions::pairs($fresh->id);

            Assignment::query()->where('exchange_id', $fresh->id)->delete();

            $pairs = $this->matcher->cycle($members, $exclusions);
            if ($pairs === null) {
                $error = count($members) < Matcher::MIN_MEMBERS ? 'too_few' : ($this->matcher->exhausted ? 'busy' : 'exclusions');
                $fresh->status = Exchange::OPEN;
                $fresh->draw_error = $error;
                $fresh->drawn_at = null;
                $fresh->save();

                return;
            }

            // 🚨 Rows go in in a shuffled order of their own: inserted round the cycle, ids
            // would follow it, and two members comparing theirs could place a third pair.
            $now = Carbon::now();
            $rows = array_map(
                fn (int $giver, int $receiver) => ['exchange_id' => $fresh->id, 'giver_id' => $giver, 'receiver_id' => $receiver, 'created_at' => $now],
                array_keys($pairs),
                $pairs
            );
            Assignment::query()->insert((new \Random\Randomizer())->shuffleArray($rows));

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

    /** Tell every Santa who they buy for (on the queue). Only from DRAWN, once. */
    public function reveal(Exchange $exchange): bool
    {
        $now = Carbon::now();
        $moved = Exchange::query()->whereKey($exchange->id)->where('status', Exchange::DRAWN)->update([
            'status' => Exchange::ACTIVE,
            'revealed_at' => $now,
            'sent_visible_at' => self::sentVisibleAt($exchange->ship_by, $now),
        ]);
        if (! $moved) {
            return false;
        }
        $exchange->refresh();
        $this->queue->push(new SendNews($exchange->id, 'matched'));

        return true;
    }
}
