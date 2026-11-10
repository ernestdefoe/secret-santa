<?php

namespace ErnestDefoe\SecretSanta\Api;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Assignment;
use ErnestDefoe\SecretSanta\Draw;
use ErnestDefoe\SecretSanta\Dropouts;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Exclusion;
use ErnestDefoe\SecretSanta\Message;
use ErnestDefoe\SecretSanta\Notification\MessageBlueprint;
use ErnestDefoe\SecretSanta\Notification\ReceivedBlueprint;
use ErnestDefoe\SecretSanta\Notification\ShippedBlueprint;
use ErnestDefoe\SecretSanta\Participant;
use ErnestDefoe\SecretSanta\Presenter;
use ErnestDefoe\SecretSanta\Reveal;
use ErnestDefoe\SecretSanta\Settings;
use ErnestDefoe\SecretSanta\ShowOffThread;
use ErnestDefoe\SecretSanta\Exclusions;
use ErnestDefoe\SecretSanta\Job\SendNews;
use ErnestDefoe\SecretSanta\Matcher;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/secret-santa/{id}/{action}. Every answer is the exchange as the
 * actor may see it (Presenter), except peek.
 *
 * Organizers:  draw (now, or again before the reveal), reveal, exclude {a, b, remove?},
 *              dropout {user}, rescue {user, volunteer}, finish, thread,
 *              peek {user, reason}: the one logged way to see a match.
 * Santas:      shipped {giftee, note, undo?}, message {giftee, body}
 * Giftees:     received {thanks, undo?}, message {to: "santa", body}
 *
 * 🚨 Assignment row ids never cross the wire, in or out: a Santa names their
 * giftee by user id (whom they know), a giftee just says "my Santa".
 */
class ActionController implements RequestHandlerInterface
{
    private const ORGANIZER = ['draw', 'reveal', 'exclude', 'dropout', 'rescue', 'finish', 'thread', 'peek'];

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Draw $draw,
        private readonly Dropouts $dropouts,
        private readonly NotificationSyncer $notifications,
        private readonly RateLimit $limit,
        private readonly ShowOffThread $thread,
        private readonly Queue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $action = (string) Arr::get($params, 'action');
        /** @var Exchange $exchange */
        $exchange = Exchange::query()->findOrFail((int) Arr::get($params, 'id'));
        $body = (array) $request->getParsedBody();

        if (in_array($action, self::ORGANIZER, true) && ! $exchange->isOrganizer($actor)) {
            throw new PermissionDeniedException();
        }

        $error = match ($action) {
            'draw' => $this->drawNow($exchange),
            'reveal' => $this->draw->reveal($exchange) ? null : 'secret_santa_wrong_state',
            'exclude' => $this->exclude($exchange, $actor, $body),
            'dropout' => $this->dropouts->remove($exchange, (int) ($body['user'] ?? 0)),
            'rescue' => $this->rescue($exchange, $body),
            'finish' => $this->finish($exchange),
            'thread' => $this->thread->start($exchange, $actor, $request),
            'peek' => null,
            'shipped', 'received' => $this->delivery($exchange, $actor, $body, $action),
            'message' => $this->message($exchange, $actor, $body),
            default => 'not_found',
        };

        if ($action === 'peek') {
            return $this->peek($exchange, $actor, $body);
        }
        if ($error) {
            return Errors::make($error === 'not_found' ? 404 : ($error === 'secret_santa_slow_down' ? 429 : 422), $error);
        }

        return new JsonResponse(['data' => $this->presenter->exchange($exchange->refresh(), $actor)]);
    }

    private function drawNow(Exchange $exchange): ?string
    {
        if (! in_array($exchange->status, [Exchange::OPEN, Exchange::DRAWN], true)) {
            return 'secret_santa_wrong_state';
        }
        $error = $this->draw->run($exchange);

        return $error ? 'secret_santa_draw_'.$error : null;
    }

    /** @param array<string, mixed> $body */
    private function exclude(Exchange $exchange, User $actor, array $body): ?string
    {
        $a = (int) ($body['a'] ?? 0);
        $b = (int) ($body['b'] ?? 0);
        $remove = ! empty($body['remove']);

        $result = Exchange::locked($exchange->id, function (Exchange $fresh) use ($a, $b, $remove, $actor) {
            if (! in_array($fresh->status, [Exchange::OPEN, Exchange::DRAWN], true)) {
                return 'secret_santa_wrong_state';
            }
            $members = Participant::query()->where('exchange_id', $fresh->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if ($a === $b || ! in_array($a, $members, true) || ! in_array($b, $members, true)) {
                return 'secret_santa_bad_pair';
            }

            if ($remove) {
                Exclusion::query()->where('exchange_id', $fresh->id)->where('user_id', min($a, $b))->where('other_id', max($a, $b))->delete();
            } else {
                // Organizers are held to the same rule as members: nobody may be left
                // with under 3 people they could be matched with.
                $pairs = Exclusions::pairs($fresh->id);
                $pairs[] = [$a, $b];
                if (Matcher::cornered($members, $pairs)) {
                    return 'secret_santa_exclusions_tight';
                }
                Exclusion::pair($fresh->id, $a, $b, $actor->id);
            }
            $fresh->draw_error = null;
            $fresh->save();

            return $fresh->status;
        });

        if ($result !== Exchange::OPEN && $result !== Exchange::DRAWN) {
            return $result ?? 'not_found';
        }
        // A drawn exchange follows the new pairs at once (nobody has been told yet).
        if ($result === Exchange::DRAWN) {
            $error = $this->draw->run($exchange->refresh());

            return $error ? 'secret_santa_draw_'.$error : null;
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function rescue(Exchange $exchange, array $body): ?string
    {
        $volunteer = User::query()->find((int) ($body['volunteer'] ?? 0));
        if (! $volunteer || ! $volunteer->can(Settings::JOIN) || ! $volunteer->can(Settings::VIEW)) {
            return 'secret_santa_bad_member';
        }

        return $this->dropouts->rescue($exchange, (int) ($body['user'] ?? 0), $volunteer) ? null : 'secret_santa_bad_member';
    }

    private function finish(Exchange $exchange): ?string
    {
        if ($exchange->status !== Exchange::ACTIVE) {
            return 'secret_santa_wrong_state';
        }
        $exchange->status = Exchange::COMPLETE;
        $exchange->completed_at = Carbon::now();
        $exchange->save();

        $this->queue->push(new SendNews($exchange->id, 'ended'));

        return null;
    }

    /**
     * Marking a gift shipped (the Santa) or received (the giftee), or taking it back.
     *
     * @param array<string, mixed> $body
     */
    private function delivery(Exchange $exchange, User $actor, array $body, string $what): ?string
    {
        if (! in_array($exchange->status, [Exchange::ACTIVE, Exchange::COMPLETE], true)) {
            return 'secret_santa_wrong_state';
        }
        /** @var Assignment|null $row */
        $row = $what === 'shipped'
            ? Assignment::query()->where('exchange_id', $exchange->id)->where('giver_id', $actor->id)->where('receiver_id', (int) ($body['giftee'] ?? 0))->first()
            : Assignment::query()->where('exchange_id', $exchange->id)->where('receiver_id', $actor->id)->first();
        if (! $row) {
            return 'not_found';
        }
        $note = Input::text($body, $what === 'shipped' ? 'note' : 'thanks', 1000);
        if ($note === null) {
            return 'secret_santa_too_long';
        }
        $undo = ! empty($body['undo']);

        if ($what === 'shipped') {
            if ($undo && $row->received_at) {
                return 'secret_santa_wrong_state';
            }
            $first = ! $row->shipped_at && ! $undo;
            $row->shipped_at = $undo ? null : ($row->shipped_at ?? Carbon::now());
            $row->gift_note = $undo ? null : ($note ?: null);
            $row->save();
            if ($first && ($receiver = User::query()->find($row->receiver_id))) {
                $this->notifications->sync(new ShippedBlueprint($exchange, $receiver), [$receiver]);
            }
        } else {
            $first = ! $row->received_at && ! $undo;
            $row->received_at = $undo ? null : ($row->received_at ?? Carbon::now());
            $row->thanks = $undo ? null : ($note ?: null);
            $row->save();
            if ($first && ($giver = User::query()->find($row->giver_id))) {
                $this->notifications->sync(new ReceivedBlueprint($exchange, $actor), [$giver]);
            }
        }

        $exchange->recount();

        return null;
    }

    /** @param array<string, mixed> $body */
    private function message(Exchange $exchange, User $actor, array $body): ?string
    {
        if (! in_array($exchange->status, [Exchange::ACTIVE, Exchange::COMPLETE], true)) {
            return 'secret_santa_wrong_state';
        }
        /** @var Assignment|null $row */
        $row = ($body['to'] ?? null) === 'santa'
            ? Assignment::query()->where('exchange_id', $exchange->id)->where('receiver_id', $actor->id)->first()
            : Assignment::query()->where('exchange_id', $exchange->id)->where('giver_id', $actor->id)->where('receiver_id', (int) ($body['giftee'] ?? 0))->first();
        if (! $row) {
            return 'not_found';
        }
        $text = Input::text($body, 'body', 1000, 1);
        if ($text === null) {
            return 'secret_santa_too_long';
        }
        if ($this->limit->exceeded($actor, 'messages', 40)) {
            return 'secret_santa_slow_down';
        }

        $fromGiver = $row->giver_id === $actor->id;
        $message = new Message();
        $message->assignment_id = $row->id;
        $message->from_giver = $fromGiver;
        $message->body = $text;
        $message->created_at = Carbon::now();
        $message->save();

        $giftee = $fromGiver ? User::query()->find($row->receiver_id) : $actor;
        $other = $fromGiver ? $giftee : User::query()->find($row->giver_id);
        if ($giftee && $other) {
            $this->notifications->sync(new MessageBlueprint($exchange, $giftee, $fromGiver, $message->id), [$other]);
        }

        return null;
    }

    /**
     * The organizer reveal: one member's Santa and giftee, for when something
     * has gone wrong. A reason is required and every look is logged where the
     * exchange's organizers can see it.
     *
     * @param array<string, mixed> $body
     */
    private function peek(Exchange $exchange, User $actor, array $body): ResponseInterface
    {
        $user = (int) ($body['user'] ?? 0);
        $reason = Input::text($body, 'reason', 200, 3);
        if ($exchange->status === Exchange::OPEN || ! Participant::query()->where('exchange_id', $exchange->id)->where('user_id', $user)->exists()) {
            return Errors::make(422, 'secret_santa_bad_member');
        }
        if ($reason === null) {
            return Errors::make(422, 'secret_santa_reason_needed');
        }

        $log = new Reveal();
        $log->exchange_id = $exchange->id;
        $log->actor_id = $actor->id;
        $log->user_id = $user;
        $log->reason = $reason;
        $log->created_at = Carbon::now();
        $log->save();

        $santa = Assignment::query()->where('exchange_id', $exchange->id)->where('receiver_id', $user)->value('giver_id');
        $giftees = Assignment::query()->where('exchange_id', $exchange->id)->where('giver_id', $user)->pluck('receiver_id')->all();
        $people = User::query()->whereIn('id', array_filter(array_merge([$santa], $giftees)))->get()->keyBy('id');

        return new JsonResponse([
            'santa' => $this->presenter->user($santa ? $people->get($santa) : null),
            'giftees' => array_values(array_filter(array_map(fn ($id) => $this->presenter->user($people->get($id)), $giftees))),
            'data' => $this->presenter->exchange($exchange, $actor),
        ]);
    }
}
