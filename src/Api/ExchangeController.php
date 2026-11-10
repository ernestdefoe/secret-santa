<?php

namespace ErnestDefoe\SecretSanta\Api;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Draw;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Participant;
use ErnestDefoe\SecretSanta\Presenter;
use ErnestDefoe\SecretSanta\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /api/secret-santa            the list page: open, under way, and past (paged)
 * POST   /api/secret-santa            start an exchange
 * GET    /api/secret-santa/{id}       one exchange, as the viewer may see it (Presenter)
 * PATCH  /api/secret-santa/{id}       the organizer edits it
 * DELETE /api/secret-santa/{id}       the organizer cancels it before matches go out; moderators any time
 *
 * Reading the list or an exchange first draws any exchange whose sign-ups
 * have closed (Draw::due), so a forum with no cron still draws on time.
 */
class ExchangeController implements RequestHandlerInterface
{
    public const PAST_PAGE = 12;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Draw $draw,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $method = $request->getMethod();

        if ($method === 'POST') {
            return $this->create($actor, (array) $request->getParsedBody());
        }
        if (! $id) {
            $this->draw->due();

            return $this->index($actor, max(0, (int) Arr::get($request->getQueryParams(), 'offset')));
        }

        $this->draw->due();
        /** @var Exchange $exchange */
        $exchange = Exchange::query()->findOrFail($id);

        if ($method === 'DELETE') {
            if (! $exchange->isOrganizer($actor)) {
                throw new PermissionDeniedException();
            }
            if (! in_array($exchange->status, [Exchange::OPEN, Exchange::DRAWN], true) && ! $actor->can(Settings::MODERATE)) {
                return Errors::make(422, 'secret_santa_too_late');
            }
            $exchange->delete();

            return new EmptyResponse(204);
        }

        if ($method === 'PATCH') {
            if (! $exchange->isOrganizer($actor)) {
                throw new PermissionDeniedException();
            }

            return $this->save($exchange, $actor, (array) $request->getParsedBody(), false);
        }

        return new JsonResponse(['data' => $this->presenter->exchange($exchange, $actor)]);
    }

    private function index(User $actor, int $offset): JsonResponse
    {
        $current = Exchange::query()->with('organizer')
            ->whereIn('status', [Exchange::OPEN, Exchange::DRAWN, Exchange::ACTIVE])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Exchange::OPEN])
            ->orderBy('signup_ends_at')
            ->limit(50)
            ->get();
        $past = Exchange::query()->with('organizer')->where('status', Exchange::COMPLETE)
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->offset($offset)->limit(self::PAST_PAGE + 1)->get();
        $more = $past->count() > self::PAST_PAGE;
        $past = $past->take(self::PAST_PAGE);
        $all = $current->concat($past);
        $ids = $all->pluck('id');

        $joined = $actor->isGuest() ? [] : Participant::query()->where('user_id', $actor->id)->whereIn('exchange_id', $ids)->pluck('exchange_id')->flip()->all();

        // A few faces per card: ids first (no text columns), then the users in one query.
        $faces = [];
        foreach (Participant::query()->whereIn('exchange_id', $ids)->orderByDesc('joined_at')->orderByDesc('id')->get(['exchange_id', 'user_id']) as $row) {
            if (count($faces[$row->exchange_id] ?? []) < 5) {
                $faces[$row->exchange_id][] = $row->user_id;
            }
        }
        $users = User::query()->whereIn('id', array_merge([], ...array_values($faces)))->get()->keyBy('id');

        $card = fn (Exchange $e) => $this->presenter->card(
            $e,
            $actor,
            isset($joined[$e->id]),
            array_values(array_filter(array_map(fn (int $id) => $users->get($id), $faces[$e->id] ?? [])))
        );

        return new JsonResponse([
            'current' => $offset ? [] : $current->map($card)->values()->all(),
            'past' => $past->map($card)->values()->all(),
            'more' => $more,
            'canStart' => $actor->can(Settings::START),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function create(User $actor, array $body): ResponseInterface
    {
        $actor->assertRegistered();
        $actor->assertCan(Settings::START);
        // Three a day, counting only exchanges actually started.
        if (Exchange::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subDay())->count() >= 3) {
            return Errors::make(429, 'secret_santa_slow_down');
        }

        $exchange = new Exchange();
        $exchange->user_id = $actor->id;
        $exchange->status = Exchange::OPEN;

        return $this->save($exchange, $actor, $body, true);
    }

    /** @param array<string, mixed> $body */
    private function save(Exchange $exchange, User $actor, array $body, bool $new): ResponseInterface
    {
        if ($exchange->status === Exchange::COMPLETE) {
            return Errors::make(422, 'secret_santa_too_late');
        }
        $open = $exchange->status === Exchange::OPEN;

        $title = Input::text($body, 'title', 100, 3);
        $description = Input::text($body, 'description', 5000);
        $budget = Input::text($body, 'budget', 40);
        $shipBy = empty($body['shipBy']) ? null : Input::date($body, 'shipBy');
        if ($title === null || $description === null || $budget === null || (! empty($body['shipBy']) && ! $shipBy)) {
            return Errors::make(422, 'secret_santa_bad_exchange');
        }

        $exchange->title = $title;
        $exchange->description = $description ?: null;
        $exchange->budget = $budget ?: null;

        if (! $open) {
            // Once sign-ups close the send-by date may only move later, and whether
            // Santas are unmasked is settled: members joined on those terms.
            $current = $exchange->ship_by;
            if (! $current && $shipBy && $shipBy->lt($exchange->signup_ends_at->copy()->startOfDay())) {
                return Errors::make(422, 'secret_santa_bad_dates');
            }
            if ($current && (! $shipBy || $shipBy->lt($current))) {
                return Errors::make(422, 'secret_santa_ship_by_later');
            }
            $exchange->ship_by = $shipBy;
        } else {
            $exchange->ship_by = $shipBy;
            $exchange->unmask = (bool) ($body['unmask'] ?? $exchange->unmask ?? true);
        }

        // What the draw depends on is settled once sign-ups close.
        if ($open) {
            $kind = $body['kind'] ?? 'physical';
            $ends = Input::moment($body, 'signupEndsAt');
            if (! in_array($kind, Exchange::KINDS, true) || ! $ends || $ends->isPast() || $ends > Carbon::now()->addYear()
                || ($shipBy && $shipBy->lt($ends->copy()->startOfDay()))) {
                return Errors::make(422, 'secret_santa_bad_dates');
            }
            $exchange->kind = $kind;
            $exchange->signup_ends_at = $ends;
            $exchange->hold_reveal = (bool) ($body['holdReveal'] ?? false);
            $exchange->draw_error = null;
        }

        $exchange->save();
        $exchange->setRelation('organizer', $exchange->organizer()->first());

        return new JsonResponse(['data' => $this->presenter->exchange($exchange, $actor)], $new ? 201 : 200);
    }
}
