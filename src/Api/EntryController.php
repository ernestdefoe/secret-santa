<?php

namespace ErnestDefoe\SecretSanta\Api;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Exclusion;
use ErnestDefoe\SecretSanta\Matcher;
use ErnestDefoe\SecretSanta\Notification\JoinedBlueprint;
use ErnestDefoe\SecretSanta\Participant;
use ErnestDefoe\SecretSanta\Presenter;
use ErnestDefoe\SecretSanta\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST   /api/secret-santa/{id}/entry   join: {wishlist, shippingNote, exclusions: [userId]}
 * PATCH  /api/secret-santa/{id}/entry   edit your wishlist and note (until the exchange ends) and, while sign-ups are open, your exclusions
 * DELETE /api/secret-santa/{id}/entry   leave, while sign-ups are open (after that, an organizer handles it as a dropout)
 *
 * Exclusions a member sets are theirs to change; ones an organizer set stay.
 */
class EntryController implements RequestHandlerInterface
{
    public const MAX_EXCLUSIONS = 3;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        /** @var Exchange $exchange */
        $exchange = Exchange::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        /** @var Participant|null $entry */
        $entry = Participant::query()->where('exchange_id', $exchange->id)->where('user_id', $actor->id)->first();
        $body = (array) $request->getParsedBody();

        if ($request->getMethod() === 'DELETE') {
            if (! $entry) {
                return Errors::make(404, 'not_found');
            }
            // Locked and re-read: a draw finishing at this moment must not lose a member mid-way.
            $left = Exchange::locked($exchange->id, function (Exchange $fresh) use ($actor) {
                if ($fresh->status !== Exchange::OPEN) {
                    return false;
                }
                Participant::query()->where('exchange_id', $fresh->id)->where('user_id', $actor->id)->delete();
                Exclusion::query()->where('exchange_id', $fresh->id)->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('other_id', $actor->id))->delete();

                return true;
            });
            if (! $left) {
                return Errors::make(422, 'secret_santa_too_late');
            }
            $exchange->refresh()->recount();

            return $this->fresh($exchange, $actor);
        }

        $joining = $request->getMethod() === 'POST';
        if ($joining) {
            $actor->assertCan(Settings::JOIN);
            if ($entry) {
                return Errors::make(422, 'secret_santa_already_joined');
            }
            if (! $exchange->signupsOpen()) {
                return Errors::make(422, 'secret_santa_closed');
            }
        } elseif (! $entry) {
            return Errors::make(404, 'not_found');
        } elseif ($exchange->status === Exchange::COMPLETE) {
            return Errors::make(422, 'secret_santa_too_late');
        }

        $wishlist = Input::text($body, 'wishlist', 3000, 3);
        $note = Input::text($body, 'shippingNote', 1000, $exchange->kind === 'physical' ? 5 : 0);
        if ($wishlist === null) {
            return Errors::make(422, 'secret_santa_bad_wishlist');
        }
        if ($note === null) {
            return Errors::make(422, 'secret_santa_bad_note');
        }

        // The exclusions first: a set that corners anyone is refused before anything is saved.
        $others = null;
        if ($exchange->status === Exchange::OPEN && array_key_exists('exclusions', $body)) {
            $others = $this->exclusionsFor($exchange, $actor, Input::ids($body, 'exclusions', self::MAX_EXCLUSIONS));
            if ($others === false) {
                return Errors::make(422, 'secret_santa_exclusions_tight');
            }
        }

        $entry ??= new Participant();
        $entry->exchange_id = $exchange->id;
        $entry->user_id = $actor->id;
        $entry->wishlist = $wishlist;
        $entry->shipping_note = $note ?: null;
        $entry->joined_at ??= Carbon::now();
        $entry->updated_at = Carbon::now();
        $entry->save();

        if (is_array($others)) {
            Exclusion::query()->where('exchange_id', $exchange->id)->where('created_by', $actor->id)
                ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('other_id', $actor->id))->delete();
            foreach ($others as $other) {
                Exclusion::pair($exchange->id, $actor->id, $other, $actor->id);
            }
        }

        if ($joining) {
            $exchange->recount();
            $organizer = $exchange->organizer;
            if ($organizer && $organizer->id !== $actor->id) {
                $this->notifications->sync(new JoinedBlueprint($exchange, $actor), [$organizer]);
            }
        }

        return $this->fresh($exchange, $actor, $joining ? 201 : 200);
    }

    /**
     * The member's own exclusions as they would be after this save, or false
     * when they would leave anyone (the member included) with under 3 people
     * they could be matched with: with only two left, a member could work out
     * their Santa. Only other members of this exchange count.
     *
     * @param list<int> $wanted
     * @return list<int>|false
     */
    private function exclusionsFor(Exchange $exchange, User $actor, array $wanted): array|false
    {
        $members = Participant::query()->where('exchange_id', $exchange->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $members = array_values(array_unique(array_merge($members, [$actor->id])));
        $others = array_values(array_intersect(array_diff($wanted, [$actor->id]), $members));

        // Everyone else's pairs stay; this member's own are replaced by $others.
        $pairs = Exclusion::query()->where('exchange_id', $exchange->id)
            ->where(fn ($q) => $q->where('created_by', '!=', $actor->id)->orWhereNull('created_by')
                ->orWhere(fn ($q) => $q->where('user_id', '!=', $actor->id)->where('other_id', '!=', $actor->id)))
            ->get()->map(fn (Exclusion $e) => [$e->user_id, $e->other_id])->all();
        foreach ($others as $other) {
            $pairs[] = [$actor->id, $other];
        }

        return $others && Matcher::cornered($members, $pairs) ? false : $others;
    }

    private function fresh(Exchange $exchange, User $actor, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->exchange($exchange->refresh(), $actor)], $status);
    }
}
