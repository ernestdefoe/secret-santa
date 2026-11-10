<?php

namespace ErnestDefoe\SecretSanta;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * 🚨 The only code that turns exchanges into JSON. Matches, wishlists,
 * shipping notes, gift notes and messages are read here per viewer and
 * nowhere else; there is no JSON:API resource for any of it, so no include
 * or relationship path can reach it.
 *
 * - Everyone who may view: the exchange, its organizer and who has joined.
 * - A member: their own entry.
 * - A Santa, once matches are revealed: their giftee, the giftee's wishlist
 *   and shipping note, and their notes to each other.
 * - A giftee: whether their gift shipped, the gift note, the notes, and who
 *   their Santa was only when the exchange is complete and unmasks Santas.
 * - Organizers: counts and per-member shipped/received flags, exclusions, the
 *   reveal log. Never a pair, except through the logged organizer reveal.
 * - 🚨 Never a row id (assignments, messages): ids follow insertion order, and
 *   members comparing them could place someone else's pair.
 */
class Presenter
{
    public function __construct(private readonly SlugManager $slugs)
    {
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatar' => $user->avatar_url,
        ] : null;
    }

    /**
     * A card on the list page.
     *
     * @param list<User> $faces
     * @return array<string, mixed>
     */
    public function card(Exchange $exchange, User $viewer, bool $joined, array $faces): array
    {
        return [
            'id' => $exchange->id,
            'title' => $exchange->title,
            'budget' => $exchange->budget,
            'kind' => $exchange->kind,
            'status' => $exchange->status,
            'signupEndsAt' => $exchange->signup_ends_at->toIso8601String(),
            'shipBy' => $exchange->ship_by?->toDateString(),
            'participantsCount' => $exchange->participants_count,
            'shippedCount' => $exchange->shipped_count,
            'receivedCount' => $exchange->received_count,
            'organizer' => $this->user($exchange->organizer),
            'joined' => $joined,
            'faces' => array_map(fn (User $u) => $this->user($u), $faces),
            'createdAt' => $exchange->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function exchange(Exchange $exchange, User $viewer): array
    {
        $exchange->loadMissing('organizer');
        $participants = Participant::query()->where('exchange_id', $exchange->id)->with('user')->orderBy('joined_at')->orderBy('id')->get();
        $mine = $participants->firstWhere('user_id', $viewer->id);
        $organizer = $exchange->isOrganizer($viewer);
        $revealed = in_array($exchange->status, [Exchange::ACTIVE, Exchange::COMPLETE], true);

        $discussion = $exchange->discussion_id ? Discussion::whereVisibleTo($viewer)->find($exchange->discussion_id) : null;

        $data = [
            'id' => $exchange->id,
            'title' => $exchange->title,
            'description' => $exchange->description,
            'budget' => $exchange->budget,
            'kind' => $exchange->kind,
            'status' => $exchange->status,
            'signupEndsAt' => $exchange->signup_ends_at->toIso8601String(),
            'shipBy' => $exchange->ship_by?->toDateString(),
            'unmask' => $exchange->unmask,
            'participantsCount' => $participants->count(),
            'shippedCount' => $exchange->shipped_count,
            'receivedCount' => $exchange->received_count,
            'drawnAt' => $revealed || $organizer ? $exchange->drawn_at?->toIso8601String() : null,
            'revealedAt' => $exchange->revealed_at?->toIso8601String(),
            'completedAt' => $exchange->completed_at?->toIso8601String(),
            'createdAt' => $exchange->created_at?->toIso8601String(),
            'organizer' => $this->user($exchange->organizer),
            'participants' => $participants->map(fn (Participant $p) => $this->user($p->user))->filter()->values()->all(),
            'discussion' => $discussion ? ['id' => $discussion->id, 'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion), 'title' => $discussion->title] : null,
            'can' => [
                'join' => ! $mine && $exchange->signupsOpen() && $viewer->can(Settings::JOIN),
                'leave' => $mine && $exchange->status === Exchange::OPEN,
                'organize' => $organizer,
                'delete' => $organizer && (in_array($exchange->status, [Exchange::OPEN, Exchange::DRAWN], true) || $viewer->can(Settings::MODERATE)),
                'startThread' => $organizer && ! $discussion && $exchange->status !== Exchange::OPEN && $viewer->can('startDiscussion'),
            ],
            'me' => null,
            'giving' => [],
            'receiving' => null,
            'organizing' => null,
        ];

        if ($mine) {
            $data['me'] = [
                'wishlist' => $mine->wishlist,
                'shippingNote' => $mine->shipping_note,
                'exclusions' => Exclusion::query()->where('exchange_id', $exchange->id)
                    ->where(fn ($q) => $q->where('user_id', $viewer->id)->orWhere('other_id', $viewer->id))
                    ->get()->map(fn (Exclusion $e) => $e->user_id === $viewer->id ? $e->other_id : $e->user_id)->values()->all(),
            ];
        }

        if ($revealed && ! $viewer->isGuest()) {
            $this->matches($exchange, $viewer, $data);
        }

        if ($organizer) {
            $data['organizing'] = $this->organizing($exchange, $participants);
        }

        return $data;
    }

    /**
     * The viewer's own two halves: the giftees they buy for, and their own gift.
     *
     * @param array<string, mixed> $data
     */
    private function matches(Exchange $exchange, User $viewer, array &$data): void
    {
        $rows = Assignment::query()->where('exchange_id', $exchange->id)
            ->where(fn ($q) => $q->where('giver_id', $viewer->id)->orWhere('receiver_id', $viewer->id))
            ->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $messages = Message::query()->whereIn('assignment_id', $rows->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('assignment_id');
        $giving = $rows->where('giver_id', $viewer->id);
        $receiving = $rows->firstWhere('receiver_id', $viewer->id);

        $giftees = Participant::query()->where('exchange_id', $exchange->id)->whereIn('user_id', $giving->pluck('receiver_id'))->get()->keyBy('user_id');
        $unmasked = $receiving && $exchange->status === Exchange::COMPLETE && $exchange->unmask;
        $people = User::query()->whereIn('id', $giving->pluck('receiver_id')->when($unmasked, fn (Collection $ids) => $ids->push($receiving->giver_id)))->get()->keyBy('id');

        foreach ($giving as $row) {
            /** @var Participant|null $entry */
            $entry = $giftees->get($row->receiver_id);
            $data['giving'][] = [
                'user' => $this->user($people->get($row->receiver_id)),
                'wishlist' => $entry?->wishlist,
                'shippingNote' => $entry?->shipping_note,
                'shippedAt' => $row->shipped_at?->toIso8601String(),
                'giftNote' => $row->gift_note,
                'receivedAt' => $row->received_at?->toIso8601String(),
                'thanks' => $row->thanks,
                'messages' => $this->messages($messages->get($row->id), true),
            ];
        }

        if ($receiving) {
            $data['receiving'] = [
                'shippedAt' => $receiving->shipped_at?->toIso8601String(),
                'giftNote' => $receiving->shipped_at ? $receiving->gift_note : null,
                'receivedAt' => $receiving->received_at?->toIso8601String(),
                'thanks' => $receiving->thanks,
                'messages' => $this->messages($messages->get($receiving->id), false),
                'santa' => $unmasked ? $this->user($people->get($receiving->giver_id)) : null,
            ];
        } elseif (in_array($viewer->id, Participant::query()->where('exchange_id', $exchange->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all(), true)) {
            // Their Santa dropped out and nobody has taken them on yet.
            $data['receiving'] = ['waiting' => true];
        }
    }

    /**
     * @param Collection<int, Message>|null $messages
     * @return list<array<string, mixed>>
     */
    private function messages(?Collection $messages, bool $viewerIsGiver): array
    {
        return ($messages ?? collect())->map(fn (Message $m) => [
            'mine' => $m->from_giver === $viewerIsGiver,
            'body' => $m->body,
            'at' => $m->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * What organizers see: who is in, who has shipped what they owe and who has
     * received theirs (as separate flags, never as a pair), exclusions, giftees
     * waiting on a rescue Santa, and the reveal log.
     *
     * 🚨 Who has sent stays hidden from every organizer until the moment fixed
     * at the reveal (Exchange::sentFlagsHidden): an organizer in the exchange
     * would see their own gift ship as one flag flips, and any organizer could
     * line flags up with what members say in public.
     *
     * @param Collection<int, Participant> $participants
     * @return array<string, mixed>
     */
    private function organizing(Exchange $exchange, Collection $participants): array
    {
        $assignments = Assignment::query()->where('exchange_id', $exchange->id)->get(['giver_id', 'receiver_id', 'shipped_at', 'received_at']);
        $revealed = in_array($exchange->status, [Exchange::ACTIVE, Exchange::COMPLETE], true);
        $sentHidden = $exchange->sentFlagsHidden();

        $owing = $assignments->groupBy('giver_id');
        $incoming = $assignments->keyBy('receiver_id');
        $reveals = Reveal::query()->where('exchange_id', $exchange->id)->orderByDesc('id')->limit(50)->get();
        $logPeople = User::query()->whereIn('id', $reveals->pluck('actor_id')->merge($reveals->pluck('user_id'))->filter()->unique())->get()->keyBy('id');

        return [
            'holdReveal' => $exchange->hold_reveal,
            'drawError' => $exchange->draw_error,
            'matchCount' => $assignments->count(),
            'sentHiddenUntil' => $sentHidden ? $exchange->sent_visible_at?->toIso8601String() : null,
            'members' => $participants->map(fn (Participant $p) => [
                'user' => $this->user($p->user),
                'joinedAt' => $p->joined_at?->toIso8601String(),
                'hasShippingNote' => (bool) trim((string) $p->shipping_note),
                'shipped' => $revealed && ! $sentHidden && $owing->has($p->user_id) ? $owing->get($p->user_id)->every(fn ($a) => $a->shipped_at !== null) : null,
                'received' => $revealed && $incoming->has($p->user_id) ? $incoming->get($p->user_id)->received_at !== null : null,
                'waiting' => $revealed && ! $incoming->has($p->user_id),
            ])->filter(fn (array $row) => $row['user'] !== null)->values()->all(),
            'exclusions' => Exclusion::query()->where('exchange_id', $exchange->id)->get()->map(fn (Exclusion $e) => [$e->user_id, $e->other_id])->all(),
            'reveals' => $reveals->map(fn (Reveal $r) => [
                'actor' => $this->user($logPeople->get($r->actor_id)),
                'user' => $this->user($logPeople->get($r->user_id)),
                'reason' => $r->reason,
                'at' => $r->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
