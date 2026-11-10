<?php

namespace ErnestDefoe\SecretSanta\Job;

use ErnestDefoe\SecretSanta\Assignment;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Notification\MatchedBlueprint;
use ErnestDefoe\SecretSanta\Notification\NewsBlueprint;
use ErnestDefoe\SecretSanta\Participant;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * The alerts that go to everyone in an exchange at once: "you're Secret
 * Santa for X" when matches go out, and "the exchange is over". One alert
 * (and maybe an email) per member, so it runs on the queue, never inside the
 * request of whoever happened to open the page at the deadline. Syncing is
 * idempotent: a retried job sends nothing twice.
 */
class SendNews extends AbstractJob
{
    public function __construct(public int $exchangeId, public string $what)
    {
    }

    public function handle(NotificationSyncer $notifications): void
    {
        /** @var Exchange|null $exchange */
        $exchange = Exchange::query()->find($this->exchangeId);
        if (! $exchange) {
            return;
        }

        if ($this->what === 'matched') {
            $assignments = Assignment::query()->where('exchange_id', $exchange->id)->get();
            $users = User::query()->whereIn('id', $assignments->pluck('giver_id')->merge($assignments->pluck('receiver_id'))->unique())->get()->keyBy('id');
            foreach ($assignments as $assignment) {
                $giver = $users->get($assignment->giver_id);
                $receiver = $users->get($assignment->receiver_id);
                if ($giver && $receiver) {
                    $notifications->sync(new MatchedBlueprint($exchange, $receiver), [$giver]);
                }
            }

            return;
        }

        foreach (User::query()->whereIn('id', Participant::query()->where('exchange_id', $exchange->id)->select('user_id'))->get() as $member) {
            $notifications->sync(new NewsBlueprint($exchange, $member, 'ended'), [$member]);
        }
    }
}
