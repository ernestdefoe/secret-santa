<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\User\User;

/**
 * News about the exchange, to one member; the subject is that member and
 * there is no from-user. $news: ended (the exchange is over: see who your
 * Santa was), released (your giftee left before you shipped: you're off the
 * hook), removed (an organizer took you out), draw_failed (to the organizer:
 * too few members, or the exclusions leave no way round).
 */
class NewsBlueprint extends SantaBlueprint
{
    public function __construct(Exchange $exchange, User $member, string $news)
    {
        parent::__construct($exchange, $member, null, ['news' => $news]);
    }

    public static function getType(): string
    {
        return 'secretSantaNews';
    }
}
