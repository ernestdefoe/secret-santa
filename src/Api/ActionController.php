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
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use ErnestDefoe\SecretSanta\Notification\ReceivedBlueprint;
use ErnestDefoe\SecretSanta\Notification\ShippedBlueprint;
use ErnestDefoe\SecretSanta\Participant;
use ErnestDefoe\SecretSanta\Presenter;
use ErnestDefoe\SecretSanta\Reveal;
use ErnestDefoe\SecretSanta\Settings;
use ErnestDefoe\SecretSanta\ShowOffThread;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
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
 * Santas:      shipped {assignment, note, undo?}, message {assignment, body}
 * Giftees:     received {assignment, thanks, undo?}, message {assignment, body}
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
            'dropout' => $this->dropout($exchange, $body),
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
        if (! in_array($exchange->status, [Exchange::OPEN, Exchange::DRAWN], true)) {
            return 'secret_santa_wrong_state';
        }
        $a = (int) ($body['a'] ?? 0);
        $b = (int) ($body['b'] ?? 0);
        $members = Participant::query()->where('exchange_id', $exchange->id)->whereIn('user_id', [$a, $b])->count();
        if ($a === $b || $members !== 2) {
            return 'secret_santa_bad_pair';
        }

        if (! empty($body['remove'])) {
            Exclusion::query()->where('exchange_id', $exchange->id)->where('user_id', min($a, $b))->where('other_id', max($a, $b))->delete();
        } else {
            Exclusion::pair($exchange->id, $a, $b, $actor->id);
        }

        $exchange->draw_error = null;
        $exchange->save();
        // A drawn exchange follows the new pairs at once (nobody has been told yet).
        if ($exchange->status === Exchange::DRAWN) {
            $error = $this->draw->run($exchange);

            return $error ? 'secret_santa_draw_'.$error : null;
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function dropout(Exchange $exchange, array $body): ?string
    {
        $user = (int) ($body['user'] ?? 0);
        if ($exchange->status === Exchange::COMPLETE || ! Participant::query()->where('exchange_id', $exchange->id)->where('user_id', $user)->exists()) {
            return 'secret_santa_bad_member';
        }
        $this->dropouts->remove($exchange, $user);

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

        foreach (User::query()->whereIn('id', Participant::query()->where('exchange_id', $exchange->id)->select('user_id'))->get() as $member) {
            $this->notifications->sync(new NewsBlueprint($exchange, $member, 'ended'), [$member]);
        }

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
        $column = $what === 'shipped' ? 'giver_id' : 'receiver_id';
        /** @var Assignment|null $row */
        $row = Assignment::query()->where('exchange_id', $exchange->id)->where($column, $actor->id)->find((int) ($body['assignment'] ?? 0));
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
        $row = Assignment::query()->where('exchange_id', $exchange->id)
            ->where(fn ($q) => $q->where('giver_id', $actor->id)->orWhere('receiver_id', $actor->id))
            ->find((int) ($body['assignment'] ?? 0));
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
