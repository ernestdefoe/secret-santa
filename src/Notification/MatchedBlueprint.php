<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/** To a Santa: "you're buying for Alice". Subject and from-user are the giftee, whom the Santa may know. */
class MatchedBlueprint extends SantaBlueprint implements MailableInterface
{
    /** @param string $change draw, or rescue (buying for a giftee whose Santa dropped out) */
    public function __construct(Exchange $exchange, User $receiver, string $change = 'draw')
    {
        parent::__construct($exchange, $receiver, $receiver, ['change' => $change]);
    }

    public static function getType(): string
    {
        return 'secretSantaMatched';
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-secret-santa::email.plain.matched', 'html' => 'ernestdefoe-secret-santa::email.html.matched'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-secret-santa.email.matched.subject', ['{title}' => $this->exchange->title]);
    }
}
