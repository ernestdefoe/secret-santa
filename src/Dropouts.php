<?php

namespace ErnestDefoe\SecretSanta;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Notification\MatchedBlueprint;
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Random\Randomizer;

/**
 * Members who drop out, and the giftees they leave behind.
 *
 * Before matches go out, a dropout just leaves and a drawn exchange is
 * re-drawn. After, nobody may learn a pair from the repair:
 *
 * - The dropout's own Santa is simply released ("you don't need to send a
 *   gift"). They are never given the dropout's giftee: knowing whom they
 *   bought for, they would learn the dropout's pair.
 * - Every gift the dropout still owed (a rescue Santa can owe two) goes to a
 *   Santa drawn at random from the rest, as a rescue: never the dropout's
 *   Santa, never someone excluded from that giftee, the least-loaded first.
 *   The organizer's view shows nothing waiting, so it doesn't tell them
 *   either. Only when nobody is eligible does the giftee wait for an
 *   organizer to pick a rescue Santa (rescue()); that one pair is known to them.
 * - A gift already shipped stands.
 * - Never below Matcher::MIN_MEMBERS once drawn: with 3 left, everyone could
 *   work out their Santa.
 *
 * Everything runs with the exchange row locked (Exchange::locked), so a
 * stale status can't race the draw.
 */
class Dropouts
{
    public function __construct(
        private readonly Draw $draw,
        private readonly NotificationSyncer $notifications,
        private readonly Randomizer $random = new Randomizer(),
    ) {
    }

    /** Null when done, else an error code. */
    public function remove(Exchange $exchange, int $userId): ?string
    {
        $outcome = Exchange::locked($exchange->id, function (Exchange $fresh) use ($userId) {
            $members = Participant::query()->where('exchange_id', $fresh->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if ($fresh->status === Exchange::COMPLETE || ! in_array($userId, $members, true)) {
                return ['error' => 'secret_santa_bad_member'];
            }
            if ($fresh->status !== Exchange::OPEN && count($members) - 1 < Matcher::MIN_MEMBERS) {
                return ['error' => 'secret_santa_too_few_left'];
            }

            $this->forget($fresh, $userId);
            if ($fresh->status !== Exchange::ACTIVE) {
                Assignment::query()->where('exchange_id', $fresh->id)->delete();

                return ['redraw' => $fresh->status === Exchange::DRAWN];
            }

            // Their own Santa, if not shipped yet, is released.
            /** @var Assignment|null $in */
            $in = Assignment::query()->where('exchange_id', $fresh->id)->where('receiver_id', $userId)->first();
            $santa = $in?->giver_id;
            $released = $in && ! $in->shipped_at;
            if ($released) {
                $in->delete();
            }

            // Every gift they still owed is re-routed at random.
            $owed = Assignment::query()->where('exchange_id', $fresh->id)->where('giver_id', $userId)->whereNull('shipped_at')->get();
            $routed = [];
            foreach ($owed as $row) {
                $row->delete();
                $rescuer = $this->pick($fresh, $row->receiver_id, array_filter([$userId, $santa]));
                if ($rescuer) {
                    Assignment::query()->insert(['exchange_id' => $fresh->id, 'giver_id' => $rescuer, 'receiver_id' => $row->receiver_id, 'created_at' => Carbon::now()]);
                }
                $routed[] = [$row->receiver_id, $rescuer];
            }

            return ['santa' => $released ? $santa : null, 'routed' => $routed];
        });

        if (! $outcome || isset($outcome['error'])) {
            return $outcome['error'] ?? 'not_found';
        }
        $exchange->refresh();
        if (! empty($outcome['redraw'])) {
            $this->draw->run($exchange);
        }
        if (isset($outcome['routed'])) {
            $this->tell($exchange, $userId, $outcome['santa'], $outcome['routed']);
        }
        $exchange->refresh();
        $exchange->recount();

        return null;
    }

    /**
     * A random rescue Santa for $receiver: a member, not $receiver, not one of
     * $never, not excluded from them; the fewest unsent gifts first, and not
     * the giftee's own giftee where there is a choice.
     *
     * @param list<int> $never
     */
    private function pick(Exchange $exchange, int $receiver, array $never): ?int
    {
        $members = Participant::query()->where('exchange_id', $exchange->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $excluded = Exclusion::query()->where('exchange_id', $exchange->id)
            ->where(fn ($q) => $q->where('user_id', $receiver)->orWhere('other_id', $receiver))->get()
            ->map(fn (Exclusion $e) => $e->user_id === $receiver ? $e->other_id : $e->user_id)->all();
        $candidates = array_values(array_diff($members, [$receiver], $never, $excluded));
        if (! $candidates) {
            return null;
        }

        $load = Assignment::query()->where('exchange_id', $exchange->id)->whereNull('shipped_at')->whereIn('giver_id', $candidates)
            ->selectRaw('giver_id, count(*) as n')->groupBy('giver_id')->pluck('n', 'giver_id');
        $theirGiftee = (int) Assignment::query()->where('exchange_id', $exchange->id)->where('giver_id', $receiver)->value('receiver_id');
        $score = fn (int $id) => 2 * (int) ($load[$id] ?? 0) + ($id === $theirGiftee ? 1 : 0);
        $best = min(array_map($score, $candidates));
        $pool = array_values(array_filter($candidates, fn (int $id) => $score($id) === $best));

        return $pool[$this->random->getInt(0, count($pool) - 1)];
    }

    /**
     * @param list<array{int, int|null}> $routed [giftee, rescue Santa or null]
     */
    private function tell(Exchange $exchange, int $dropoutId, ?int $santaId, array $routed): void
    {
        $ids = array_filter(array_merge([$dropoutId, $santaId], ...array_map(fn ($r) => $r, $routed)));
        $people = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        $dropout = $people->get($dropoutId);

        foreach ($routed as [$giftee, $rescuer]) {
            if (! $gifteeUser = $people->get($giftee)) {
                continue;
            }
            // The dropout's "you're buying for <giftee>" goes; the rescuer's arrives.
            $this->notifications->sync(new MatchedBlueprint($exchange, $gifteeUser), []);
            $rescuerUser = $rescuer ? $people->get($rescuer) : null;
            $this->notifications->sync(new MatchedBlueprint($exchange, $gifteeUser, 'rescue'), $rescuerUser ? [$rescuerUser] : []);
        }

        if ($dropout && ($santa = $santaId ? $people->get($santaId) : null)) {
            foreach (['draw', 'rescue'] as $change) {
                $this->notifications->sync(new MatchedBlueprint($exchange, $dropout, $change), []);
            }
            $this->notifications->sync(new NewsBlueprint($exchange, $santa, 'released'), [$santa]);
        }
        if ($dropout) {
            $this->notifications->sync(new NewsBlueprint($exchange, $dropout, 'removed'), [$dropout]);
        }
    }

    /**
     * Giftees nobody is buying for (no rescue Santa was eligible).
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

    /** An organizer picks a volunteer from the exchange to buy for a giftee left waiting. */
    public function rescue(Exchange $exchange, int $receiverId, User $volunteer): bool
    {
        $done = Exchange::locked($exchange->id, function (Exchange $fresh) use ($receiverId, $volunteer) {
            // Only someone already in the exchange: the volunteer gets the giftee's address.
            $inIt = Participant::query()->where('exchange_id', $fresh->id)->where('user_id', $volunteer->id)->exists();
            if ($fresh->status !== Exchange::ACTIVE || ! $inIt || $receiverId === $volunteer->id || ! in_array($receiverId, $this->orphans($fresh), true)) {
                return false;
            }
            Assignment::query()->insert(['exchange_id' => $fresh->id, 'giver_id' => $volunteer->id, 'receiver_id' => $receiverId, 'created_at' => Carbon::now()]);

            return true;
        });

        if ($done && ($receiver = User::query()->find($receiverId))) {
            $this->notifications->sync(new MatchedBlueprint($exchange, $receiver, 'rescue'), [$volunteer]);
        }

        return (bool) $done;
    }

    private function forget(Exchange $exchange, int $userId): void
    {
        Participant::query()->where('exchange_id', $exchange->id)->where('user_id', $userId)->delete();
        Exclusion::query()->where('exchange_id', $exchange->id)->where(fn ($q) => $q->where('user_id', $userId)->orWhere('other_id', $userId))->delete();
    }
}
