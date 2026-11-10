<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Every Secret Santa notification has a User subject (a model with an API
 * resource: one without 500s every notification list) and carries the
 * exchange in its data.
 *
 * 🚨 Privacy: the subject and the from-user go out with the notification. A
 * notification a GIFTEE gets must never name their Santa: its subject is the
 * giftee themselves and it has no from-user (see Shipped, Message).
 */
abstract class SantaBlueprint implements BlueprintInterface, AlertableInterface
{
    /** @param array<string, mixed> $extra */
    public function __construct(public Exchange $exchange, public User $subject, public ?User $from = null, public array $extra = [])
    {
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->subject;
    }

    public function getData(): mixed
    {
        return ['exchange' => $this->exchange->id, 'title' => $this->exchange->title] + $this->extra;
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
