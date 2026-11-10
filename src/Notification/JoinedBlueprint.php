<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\User\User;

/** To the organizer: "Bob joined your exchange". */
class JoinedBlueprint extends SantaBlueprint
{
    public function __construct(Exchange $exchange, User $member)
    {
        parent::__construct($exchange, $member, $member);
    }

    public static function getType(): string
    {
        return 'secretSantaJoined';
    }
}
