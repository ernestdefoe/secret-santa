<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\User\User;

/** To a Santa: "Alice received your gift". */
class ReceivedBlueprint extends SantaBlueprint
{
    public function __construct(Exchange $exchange, User $receiver)
    {
        parent::__construct($exchange, $receiver, $receiver);
    }

    public static function getType(): string
    {
        return 'secretSantaReceived';
    }
}
