<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Notification\MatchedBlueprint;
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Members who drop out, and the giftees they leave behind.
 *
 * Before matches go out, a dropout just leaves and a drawn exchange is
 * re-drawn. After, the cycle is mended without anyone learning a pair: the
 * dropout's Santa (S) now buys for the dropout's giftee (R). A gift that has
 * already shipped stands. If that leaves a giftee with no Santa (the dropout
 * never shipped but S already had), the organizer picks a rescue Santa
 * (rescue()), and only that pair is known to them.
 */
class Dropouts
{
    public function __construct(
        private readonly Draw $draw,
        private readonly NotificationSyncer $notifications,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function remove(Exchange $exchange, int $userId): void
    {
        if (in_array($exchange->status, [Exchange::OPEN, Exchange::DRAWN], true)) {
            $this->forget($exchange, $userId);
            Assignment::query()->where('exchange_id', $exchange->id)->delete();
            if ($exchange->status === Exchange::DRAWN) {
                $this->draw->run($exchange);
            }
            $exchange->refresh();
            $exchange->recount();

            return;
        }

        $santa = $orphan = null;
        $swapped = false;
        $this->db->transaction(function () use ($exchange, $userId, &$santa, &$orphan, &$swapped) {
            $this->forget($exchange, $userId);

            // A gift the dropout still owed: that giftee is now waiting on a Santa.
            /** @var Assignment|null $out */
            $out = Assignment::query()->where('exchange_id', $exchange->id)->where('giver_id', $userId)->whereNull('shipped_at')->first();
            $orphan = $out?->receiver_id;
            $out?->delete();

            // The dropout's own Santa, if they haven't shipped yet, moves on to that giftee.
            /** @var Assignment|null $in */
            $in = Assignment::query()->where('exchange_id', $exchange->id)->where('receiver_id', $userId)->whereNull('shipped_at')->first();
            if ($in) {
                $santa = $in->giver_id;
                $in->delete();
                if ($orphan && $orphan !== $santa) {
                    Assignment::query()->insert(['exchange_id' => $exchange->id, 'giver_id' => $santa, 'receiver_id' => $orphan, 'created_at' => Carbon::now()]);
                    $swapped = true;
                }
            }
        });

        $people = User::query()->whereIn('id', array_filter([$userId, $santa, $orphan]))->get()->keyBy('id');
        $dropout = $people->get($userId);
        $santaUser = $santa ? $people->get($santa) : null;
        $orphanUser = $orphan ? $people->get($orphan) : null;

        // Take back the alerts that named someone this no longer applies to.
        if ($orphanUser) {
            foreach (['draw', 'swap', 'rescue'] as $change) {
                $this->notifications->sync(new MatchedBlueprint($exchange, $orphanUser, $change), $swapped && $change === 'swap' && $santaUser ? [$santaUser] : []);
            }
        }
        if ($dropout && $santaUser) {
            foreach (['draw', 'swap', 'rescue'] as $change) {
                $this->notifications->sync(new MatchedBlueprint($exchange, $dropout, $change), []);
            }
            if (! $swapped) {
                $this->notifications->sync(new NewsBlueprint($exchange, $santaUser, 'released'), [$santaUser]);
            }
        }
        if ($dropout) {
            $this->notifications->sync(new NewsBlueprint($exchange, $dropout, 'removed'), [$dropout]);
        }

        $exchange->recount();
    }

    /**
     * Giftees nobody is buying for (after a dropout).
     *
     * @return list<int>
     */
    public function orphans(Exchange $exchange): array
    {
        if (! in_array($exchange->status, [Exchange::ACTIVE, Exchange::COMPLETE], true)) {
            return [];
        }

        return Participant::query()->where('exchange_id', $exchange->id)
            ->whereNotIn('user_id', Assignment::query()->where('exchange_id', $exchange->id)->select('receiver_id'))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** A volunteer buys for a giftee whose Santa dropped out. */
    public function rescue(Exchange $exchange, int $receiverId, User $volunteer): bool
    {
        if ($exchange->status !== Exchange::ACTIVE || $receiverId === $volunteer->id || ! in_array($receiverId, $this->orphans($exchange), true)) {
            return false;
        }
        $receiver = User::query()->find($receiverId);
        if (! $receiver) {
            return false;
        }

        Assignment::query()->insert(['exchange_id' => $exchange->id, 'giver_id' => $volunteer->id, 'receiver_id' => $receiverId, 'created_at' => Carbon::now()]);
        $this->notifications->sync(new MatchedBlueprint($exchange, $receiver, 'rescue'), [$volunteer]);

        return true;
    }

    private function forget(Exchange $exchange, int $userId): void
    {
        Participant::query()->where('exchange_id', $exchange->id)->where('user_id', $userId)->delete();
        Exclusion::query()->where('exchange_id', $exchange->id)->where(fn ($q) => $q->where('user_id', $userId)->orWhere('other_id', $userId))->delete();
    }
}
