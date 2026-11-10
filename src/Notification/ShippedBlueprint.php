<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/** To a giftee: "your Secret Santa sent your gift". The subject is the giftee; no from-user, so the Santa stays secret. */
class ShippedBlueprint extends SantaBlueprint implements MailableInterface
{
    public function __construct(Exchange $exchange, User $receiver)
    {
        parent::__construct($exchange, $receiver);
    }

    public static function getType(): string
    {
        return 'secretSantaShipped';
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-secret-santa::email.plain.shipped', 'html' => 'ernestdefoe-secret-santa::email.html.shipped'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-secret-santa.email.shipped.subject', ['{title}' => $this->exchange->title]);
    }
}
